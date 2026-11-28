<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * V2 demo data: projects, client organizations and their associations.
 *
 * SAFE on an existing database: it only ADDS rows (firstOrCreate / syncWithoutDetaching)
 * and never changes existing users or tickets, so it does not invent a client or
 * project for historical tickets.
 *
 *   php artisan db:seed --class=V2DemoDataSeeder
 *
 * DatabaseSeeder (fresh installs) calls it too and then links the demo customers
 * to clients and puts some demo tickets into projects.
 */
class V2DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [];
        foreach ([
            ['HRMS', 'Human Resource Management System', 'Employees, leave, attendance and appraisals.', true],
            ['ERP', 'Enterprise Resource Planning', 'Finance, procurement and sales modules.', true],
            ['PAYROLL', 'Payroll Management System', 'Salary processing, payslips and tax deductions.', true],
            ['ACCT', 'Accounting System', 'General ledger, invoices and financial reports.', true],
            // Inactive: cannot be chosen for new tickets, but its old tickets stay visible.
            ['INV', 'Inventory Management System', 'Discontinued product, replaced by the ERP stock module.', false],
        ] as [$code, $name, $description, $isActive]) {
            $projects[$code] = Project::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'is_active' => $isActive]
            );
        }

        $clients = [];
        foreach ([
            ['ACME', 'Acme Trading Ltd.', 'it@acme-trading.example', '+880 1711-000001', 'Gulshan 2, Dhaka', true],
            ['GLOBEX', 'Globex Pharmaceuticals', 'support@globex.example', '+880 1711-000002', 'Agrabad, Chattogram', true],
            // Inactive: its customers cannot create new tickets.
            ['BRIGHT', 'Bright Garments (former client)', 'admin@bright.example', null, 'Gazipur', false],
        ] as [$code, $name, $email, $phone, $address, $isActive]) {
            $clients[$code] = Client::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $isActive]
            );
        }

        // Which client uses which project.
        $projects['HRMS']->clients()->syncWithoutDetaching([$clients['ACME']->id, $clients['GLOBEX']->id, $clients['BRIGHT']->id]);
        $projects['PAYROLL']->clients()->syncWithoutDetaching([$clients['ACME']->id]);
        $projects['ERP']->clients()->syncWithoutDetaching([$clients['GLOBEX']->id]);
        $projects['INV']->clients()->syncWithoutDetaching([$clients['GLOBEX']->id]);

        // Which agent supports which project (only existing, active demo agents).
        $agentProjects = [
            'sarah.agent@resolvedesk.test' => ['HRMS', 'PAYROLL'],
            'david.agent@resolvedesk.test' => ['ERP', 'HRMS'],
            'fatima.agent@resolvedesk.test' => ['PAYROLL', 'ACCT'],
            'michael.agent@resolvedesk.test' => ['ERP', 'INV'],
        ];

        foreach ($agentProjects as $email => $codes) {
            $agent = User::where('email', $email)->where('role', User::ROLE_AGENT)->where('is_active', true)->first();
            if ($agent === null) {
                continue;
            }

            foreach ($codes as $code) {
                $projects[$code]->agents()->syncWithoutDetaching([$agent->id]);
            }
        }
    }
}
