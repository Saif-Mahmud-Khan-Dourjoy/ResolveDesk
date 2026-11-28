<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * V2 Phase 5: internal notes (staff only) and secure private attachments.
 */
class InternalNotesAndAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $customer;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local'); // files go to a temporary fake disk

        $this->admin = User::factory()->admin()->create();
        $this->agent = User::factory()->agent()->create();
        $this->customer = User::factory()->customer()->create();
        $this->ticket = Ticket::factory()->inProgress($this->agent)->create(['created_by' => $this->customer->id]);
    }

    // ---- Internal notes -----------------------------------------------------------

    public function test_agent_can_add_an_internal_note_that_customers_never_see(): void
    {
        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Customer DB looks corrupted - do not tell them yet',
            'visibility' => 'internal',
        ])->assertSessionHasNoErrors();

        $this->assertSame(TicketComment::VISIBILITY_INTERNAL, TicketComment::first()->visibility);

        // Blade: customer does not see the note or the internal activity.
        $this->actingAs($this->customer)->get(route('tickets.show', $this->ticket))
            ->assertOk()
            ->assertDontSee('Customer DB looks corrupted')
            ->assertDontSee('Added an internal note');

        // Staff see it.
        $this->actingAs($this->admin)->get(route('tickets.show', $this->ticket))
            ->assertSee('Customer DB looks corrupted');
    }

    public function test_internal_note_never_notifies_or_emails_the_customer(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Escalate to tier 2', 'visibility' => 'internal',
        ]);

        $this->assertSame(0, $this->customer->notifications()->count());
        $this->assertSame(1, $this->agent->notifications()->count(), 'staff participant is notified');
        $this->assertSame('internal_note', $this->agent->notifications()->first()->data['event_type']);
        Queue::assertNothingPushed(); // no email for internal notes

        // JSON feed of the customer contains nothing about it.
        $this->actingAs($this->customer)->getJson(route('notifications.feed'))
            ->assertJsonCount(0, 'notifications');
    }

    public function test_customer_cannot_post_an_internal_note_even_by_editing_the_form(): void
    {
        $this->actingAs($this->customer)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Sneaky', 'visibility' => 'internal',
        ])->assertForbidden();

        $this->assertSame(0, TicketComment::count());
    }

    public function test_public_reply_is_visible_to_the_customer(): void
    {
        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'We deployed a fix, please retry.', 'visibility' => 'public',
        ]);

        $this->actingAs($this->customer)->get(route('tickets.show', $this->ticket))
            ->assertSee('We deployed a fix, please retry.');
        $this->assertSame(1, $this->customer->notifications()->count());
    }

    // ---- Attachments --------------------------------------------------------------

    public function test_ticket_can_be_created_with_attachments_stored_privately(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->customer)->post('/tickets', [
            'subject' => 'Screenshot of the error', 'description' => 'See attached', 'category_id' => $category->id,
            'priority' => 'low', 'request_token' => (string) Str::uuid(),
            'attachments' => [UploadedFile::fake()->image('error.png'), UploadedFile::fake()->create('log.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TicketAttachment::count());
        $attachment = TicketAttachment::where('original_name', 'error.png')->first();
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertStringStartsWith('ticket-attachments/', $attachment->storage_path);
        $this->assertStringNotContainsString('error', $attachment->storage_path, 'generated storage name, not the user file name');
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->customer)->post('/tickets', [
            'subject' => 'x', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low',
            'request_token' => (string) Str::uuid(),
            'attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(1, Ticket::count(), 'only the ticket from setUp() exists');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_file_larger_than_the_limit_is_rejected(): void
    {
        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Big file',
            'attachments' => [UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf')], // 6 MB > 5 MB
        ])->assertSessionHasErrors('attachments.0');
    }

    public function test_participant_can_download_and_others_cannot(): void
    {
        $this->actingAs($this->customer)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Here is the invoice', 'attachments' => [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')],
        ]);
        $attachment = TicketAttachment::firstOrFail();
        $url = route('tickets.attachments.download', [$this->ticket, $attachment]);

        $this->actingAs($this->agent)->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('invoice.pdf');

        $stranger = User::factory()->customer()->create();
        $this->actingAs($stranger)->get($url)->assertForbidden();
    }

    public function test_internal_note_attachment_is_staff_only(): void
    {
        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), [
            'message' => 'Server log', 'visibility' => 'internal',
            'attachments' => [UploadedFile::fake()->create('server.txt', 5, 'text/plain')],
        ]);
        $attachment = TicketAttachment::firstOrFail();
        $url = route('tickets.attachments.download', [$this->ticket, $attachment]);

        $this->actingAs($this->customer)->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_attachment_of_another_ticket_cannot_be_downloaded_through_this_ticket(): void
    {
        $otherTicket = Ticket::factory()->create();
        $this->actingAs($this->admin)->post(route('tickets.comments.store', $otherTicket), [
            'message' => 'Other', 'attachments' => [UploadedFile::fake()->create('other.pdf', 5, 'application/pdf')],
        ]);
        $foreignAttachment = TicketAttachment::firstOrFail();

        // The customer can view $this->ticket, but the file belongs to $otherTicket (IDOR attempt).
        $this->actingAs($this->customer)
            ->get(route('tickets.attachments.download', [$this->ticket, $foreignAttachment]))
            ->assertForbidden();
    }

    public function test_files_are_deleted_when_the_database_operation_fails(): void
    {
        $this->ticket->status = Ticket::STATUS_CLOSED; // comments are rejected inside the transaction
        $this->ticket->save();

        // Call the service directly (the policy would already block closed tickets) to reach
        // the rule INSIDE the transaction: the file is written first, then the DB step fails.
        try {
            app(TicketService::class)->addComment(
                $this->ticket->fresh(), 'Late reply', 'public', $this->admin, [UploadedFile::fake()->create('late.pdf', 5, 'application/pdf')]
            );
            $this->fail('A comment on a closed ticket must be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame([], Storage::disk('local')->allFiles(), 'no orphaned file left on disk');
        $this->assertSame(0, TicketAttachment::count());
    }
}
