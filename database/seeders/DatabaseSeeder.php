<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\PricingAndPromotions\Models\Promotion;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Product;
use App\Domains\Purchasing\Models\Supplier;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Roles & Permissions
        $adminRole = Role::create([
            'code' => 'ADMIN',
            'name' => 'Super Administrator',
            'badge_color' => '#ef4444',
            'description' => 'Unrestricted access to all modules and configurations',
            'is_system' => true,
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'allowDiscount' => true, 'voidInvoice' => true, 'openCashDrawer' => true, 'viewCostPrice' => true],
                'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'adjustStock' => true, 'viewCostPrice' => true],
                'workshop' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'assignMechanic' => true, 'approveJobCard' => true],
                'finances' => ['view' => true, 'viewProfitLoss' => true, 'approveCreditLimit' => true, 'exportReports' => true],
                'purchases' => ['view' => true, 'create' => true, 'approvePO' => true, 'delete' => true],
                'settings' => ['view' => true, 'manageRoles' => true, 'systemConfig' => true],
            ],
        ]);

        $cashierRole = Role::create([
            'code' => 'CASHIER',
            'name' => 'Head Cashier',
            'badge_color' => '#10b981',
            'description' => 'Daily POS operations, customer sales, and debt settlements',
            'is_system' => false,
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'allowDiscount' => true, 'voidInvoice' => false, 'openCashDrawer' => true, 'viewCostPrice' => false],
                'inventory' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'adjustStock' => false, 'viewCostPrice' => false],
                'workshop' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'assignMechanic' => false, 'approveJobCard' => false],
                'finances' => ['view' => false, 'viewProfitLoss' => false, 'approveCreditLimit' => false, 'exportReports' => false],
                'purchases' => ['view' => false, 'create' => false, 'approvePO' => false, 'delete' => false],
                'settings' => ['view' => false, 'manageRoles' => false, 'systemConfig' => false],
            ],
        ]);

        $supervisorRole = Role::create([
            'code' => 'SUPERVISOR',
            'name' => 'Floor Supervisor',
            'badge_color' => '#f59e0b',
            'description' => 'Shift supervisor capable of approving discounts and overrides',
            'is_system' => false,
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'allowDiscount' => true, 'voidInvoice' => true, 'openCashDrawer' => true, 'viewCostPrice' => true],
                'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false, 'adjustStock' => true, 'viewCostPrice' => true],
                'workshop' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false, 'assignMechanic' => true, 'approveJobCard' => true],
                'finances' => ['view' => true, 'viewProfitLoss' => false, 'approveCreditLimit' => true, 'exportReports' => true],
                'purchases' => ['view' => true, 'create' => true, 'approvePO' => false, 'delete' => false],
                'settings' => ['view' => false, 'manageRoles' => false, 'systemConfig' => false],
            ],
        ]);

        // 2. Seed Staff Users
        $adminUser = StaffUser::create([
            'name' => 'Super Admin',
            'email' => 'admin@dotpos.com',
            'phone' => '+959111222333',
            'role_id' => $adminRole->id,
            'pin_hash' => Hash::make('9999'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
            'workstation' => 'Central Server',
        ]);

        $cashierUser = StaffUser::create([
            'name' => 'Kyaw Kyaw (Lead Cashier)',
            'email' => 'cashier@dotpos.com',
            'phone' => '+959444555666',
            'role_id' => $cashierRole->id,
            'pin_hash' => Hash::make('1234'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
            'workstation' => 'Counter-01',
        ]);

        // 3. Seed Price Lists
        $retailPL = PriceList::create([
            'code' => 'PL-RETAIL',
            'name' => 'Standard Retail Pricing',
            'type' => 'RETAIL',
            'is_default' => true,
            'is_active' => true,
            'description' => 'Default pricing for regular retail and walk-in shoppers',
        ]);

        $wholesalePL = PriceList::create([
            'code' => 'PL-WHOLESALE',
            'name' => 'Wholesale Tier (Volume Buyers)',
            'type' => 'WHOLESALE',
            'is_default' => false,
            'is_active' => true,
            'description' => 'Discounted tier for high-volume purchasing clients',
        ]);

        $fleetPL = PriceList::create([
            'code' => 'PL-FLEET',
            'name' => 'Corporate Fleet & Workshop VIP',
            'type' => 'FLEET',
            'is_default' => false,
            'is_active' => true,
            'description' => 'Contracted rates for logistics fleets and taxi associations',
        ]);

        // 4. Seed Categories & UOMs
        $catOils = Category::create(['code' => 'CAT-OIL', 'name' => 'Engine & Transmission Oils']);
        $catFilters = Category::create(['code' => 'CAT-FLT', 'name' => 'Oil, Air & Fuel Filters']);
        $catBrakes = Category::create(['code' => 'CAT-BRK', 'name' => 'Brake Pads & Rotors']);
        $catTires = Category::create(['code' => 'CAT-TIR', 'name' => 'Tires & Alignment']);
        $catLabor = Category::create(['code' => 'CAT-LBR', 'name' => 'Workshop Labor & Diagnostics']);

        $uomCan = UnitOfMeasure::create(['name' => 'Can', 'description' => '4L or 1L Engine Oil Can']);
        $uomPiece = UnitOfMeasure::create(['name' => 'Piece', 'description' => 'Individual unit count']);
        $uomSet = UnitOfMeasure::create(['name' => 'Set', 'description' => 'Pair / Set of items (Front/Rear)']);

        // 5. Seed Suppliers
        $supMoeSet = Supplier::create([
            'name' => 'Moe Set Spare Parts Distribution',
            'phone' => '+959777888999',
            'contact_person' => 'U Moe Set',
            'address' => 'Bogyoke Road, Yangon',
            'outstanding_balance' => 0.00,
        ]);

        $supTotal = Supplier::create([
            'name' => 'TotalEnergies Myanmar Direct',
            'phone' => '+959222333444',
            'contact_person' => 'Daw Ni Ni',
            'address' => 'Bayintnaung Industrial Zone',
            'outstanding_balance' => 0.00,
        ]);

        // 6. Seed Products
        $p1 = Product::create([
            'category_id' => $catOils->id,
            'uom_id' => $uomCan->id,
            'supplier_id' => $supTotal->id,
            'name' => 'Total Quartz 9000 5W-30 (4L Fully Synthetic)',
            'barcode' => '8851234567890',
            'oem_part_no' => 'TQ-9000-5W30',
            'car_fitment' => 'Toyota Alphard, Camry, Honda CR-V, Mazda CX-5',
            'bin_location' => 'Rack A-01',
            'cost_price' => 45000.00,
            'sell_price' => 58000.00,
            'wholesale_price' => 52000.00,
            'stock' => 35,
            'min_stock' => 5,
            'is_labor' => false,
        ]);

        $p2 = Product::create([
            'category_id' => $catFilters->id,
            'uom_id' => $uomPiece->id,
            'supplier_id' => $supMoeSet->id,
            'name' => 'Denso Genuine Oil Filter (Alphard / Harrier / Crown)',
            'barcode' => '8859876543210',
            'oem_part_no' => '04152-YZZA6',
            'car_fitment' => 'Toyota 2.5L / 3.5L V6 engines',
            'bin_location' => 'Rack B-03',
            'cost_price' => 8500.00,
            'sell_price' => 14000.00,
            'wholesale_price' => 11000.00,
            'stock' => 50,
            'min_stock' => 10,
            'is_labor' => false,
        ]);

        $p3 = Product::create([
            'category_id' => $catBrakes->id,
            'uom_id' => $uomSet->id,
            'supplier_id' => $supMoeSet->id,
            'name' => 'Brembo Ceramic Front Brake Pads (Alphard AH30)',
            'barcode' => '8020584065432',
            'oem_part_no' => 'P83-145',
            'car_fitment' => 'Toyota Alphard / Vellfire 2015-2023',
            'bin_location' => 'Rack C-02',
            'cost_price' => 55000.00,
            'sell_price' => 85000.00,
            'wholesale_price' => 75000.00,
            'stock' => 12,
            'min_stock' => 3,
            'is_labor' => false,
        ]);

        $pLabor = Product::create([
            'category_id' => $catLabor->id,
            'uom_id' => $uomPiece->id,
            'name' => 'Routine Maintenance & Multi-Point Inspection Labor',
            'barcode' => 'LBR-ROUTINE-01',
            'cost_price' => 0.00,
            'sell_price' => 25000.00,
            'stock' => 9999,
            'min_stock' => 0,
            'is_labor' => true,
        ]);

        // 7. Seed Fleets
        $fleetKBZ = Fleet::create([
            'name' => 'KBZ Corporate Executive VIP Fleet',
            'type' => 'Corporate / Executive',
            'credit_limit' => 5000000.00,
            'notes' => 'Contract corporate client. Billed monthly.',
        ]);

        // 8. Seed Customers
        $custWalkIn = Customer::create([
            'name' => 'Walk-In Customer',
            'phone' => '09000000000',
            'tier' => 'Standard',
            'price_list_id' => $retailPL->id,
            'due_amount' => 0.00,
            'credit_limit' => 0.00,
        ]);

        $custVip = Customer::create([
            'name' => 'U Hla Maung (Executive VIP)',
            'phone' => '09778123456',
            'email' => 'hlamaung@gmail.com',
            'fleet_id' => $fleetKBZ->id,
            'tier' => 'VIP',
            'price_list_id' => $fleetPL->id,
            'due_amount' => 125000.00,
            'credit_limit' => 1000000.00,
            'loyalty_points' => 450,
        ]);

        // 9. Seed Vehicles
        Vehicle::create([
            'plate_number' => '3I/4411',
            'customer_id' => $custVip->id,
            'fleet_id' => $fleetKBZ->id,
            'make' => 'Toyota',
            'model' => 'Alphard Executive Lounge',
            'year' => 2021,
            'vin' => 'AGH30-0192842',
            'engine_no' => '2AR-FE-9921',
            'color' => 'Pearl White',
            'transmission' => 'Automatic',
            'fuel_type' => 'Petrol',
            'current_mileage' => 42150,
            'notes' => 'VIP vehicle. Requires synthetic oil only.',
        ]);

        Vehicle::create([
            'plate_number' => '1D/5678',
            'customer_id' => $custVip->id,
            'fleet_id' => $fleetKBZ->id,
            'make' => 'Toyota',
            'model' => 'Crown Royal Saloon',
            'year' => 2018,
            'vin' => 'GRS210-55123',
            'engine_no' => '4GR-FSE-1234',
            'color' => 'Black',
            'current_mileage' => 78400,
        ]);

        // 10. Seed Mechanics
        Mechanic::create([
            'name' => 'Ko Zaw (Master Technician)',
            'phone' => '+95955512345',
            'specialization' => 'Engine Overhaul & Diagnostics',
            'commission_rate' => 20.00, // 20% commission on labor
            'status' => 'Active',
        ]);

        Mechanic::create([
            'name' => 'Ko Phyo (Auto Electrician)',
            'phone' => '+95955567890',
            'specialization' => 'Wiring, ECU & Hybrid Battery Service',
            'commission_rate' => 18.00,
            'status' => 'Active',
        ]);

        // 11. Seed Promotions
        Promotion::create([
            'code' => 'VOUCHER-VIP10',
            'title' => 'VIP 10% Discount Coupon',
            'type' => 'percent',
            'value' => 10.00,
            'min_spend' => 50000.00,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'is_active' => true,
            'target_tier' => 'All',
            'description' => '10% off for purchases over 50,000 Ks',
        ]);
    }
}
