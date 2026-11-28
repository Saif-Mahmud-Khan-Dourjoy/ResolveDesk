<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * V2: client organization management (administrators only).
 * Project associations are edited on the project page (one place for the rules);
 * this page shows them read-only. Clients are deactivated, never deleted.
 */
class ClientController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $query = Client::withCount(['projects', 'customers', 'tickets']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $clients = $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();

        return view('clients.index', ['clients' => $clients]);
    }

    public function create()
    {
        return view('clients.create');
    }

    public function store(Request $request)
    {
        $client = Client::create($this->validateClient($request));
        $this->clearCaches();

        return redirect()->route('clients.show', $client)->with('success', "Client {$client->code} was created.");
    }

    public function show(Client $client)
    {
        $client->load(['projects', 'customers']);

        $tickets = Ticket::with(['project', 'creator', 'assignedAgent'])
            ->where('client_id', $client->id)
            ->latest()
            ->paginate(10);

        return view('clients.show', [
            'client' => $client,
            'tickets' => $tickets,
            'stats' => Ticket::slaSummary(Ticket::where('client_id', $client->id)),
        ]);
    }

    public function edit(Client $client)
    {
        return view('clients.edit', ['client' => $client]);
    }

    public function update(Request $request, Client $client)
    {
        // Deactivation: the client's customers can no longer create tickets
        // (TicketPolicy::create), but existing tickets stay viewable and can be finished.
        $client->update($this->validateClient($request, $client));
        $this->clearCaches();

        return redirect()->route('clients.show', $client)->with('success', 'Client updated.');
    }

    private function validateClient(Request $request, ?Client $client = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('clients', 'code')->ignore($client?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    private function clearCaches(): void
    {
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
    }
}
