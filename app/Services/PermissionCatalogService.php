<?php

namespace App\Services;

class PermissionCatalogService
{
    /**
     * Canonical system permissions dictionary matching enterprise frontend specification.
     *
     * @return array<string, mixed>
     */
    public function getCatalog(): array
    {
        return [
            'pos' => [
                'titleEn' => 'POS & Counter Sales',
                'titleMm' => 'အရောင်းကောင်တာနှင့် ဘေလ်စနစ်',
                'icon' => 'fa-solid fa-cash-register text-emerald-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'Access POS Terminal',
                        'labelMm' => 'အရောင်းကောင်တာ ဝင်ရောက်ခွင့်',
                        'descEn' => 'Allow staff to open the POS counter interface',
                        'descMm' => 'အရောင်းကောင်တာ စာမျက်နှာကို ဖွင့်လှစ်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'create',
                        'labelEn' => 'Process Sales Invoices',
                        'labelMm' => 'အရောင်းပြေစာ ဖွင့်ခွင့်',
                        'descEn' => 'Issue sales orders, print customer receipts',
                        'descMm' => 'အရောင်းဘောက်ချာ ထုတ်ပေးခွင့်နှင့် ဘေလ်ပရင့်ထုတ်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'allowDiscount',
                        'labelEn' => 'Apply Custom Discounts',
                        'labelMm' => 'လျှော့စျေး ပေးခွင့်',
                        'descEn' => 'Override line item and order-level discount rates',
                        'descMm' => 'ပစ္စည်းနှင့် ဘေလ်တစ်ခုချင်းအလိုက် လျှော့စျေးပေးခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'voidInvoice',
                        'labelEn' => 'Void / Cancel Receipts',
                        'labelMm' => 'ပြေစာ ဖျက်သိမ်းခွင့်',
                        'descEn' => 'Cancel paid invoices or void counter transactions',
                        'descMm' => 'ငွေရှင်းပြီး ပြေစာများကို ပယ်ဖျက်ခွင့် (ကြီးကြပ်သူသာ ခွင့်ပြုသင့်သည်)',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'openCashDrawer',
                        'labelEn' => 'Manual Cash Drawer Kick',
                        'labelMm' => 'ငွေအံဆွဲ လက်ဖြင့် ဖွင့်ခွင့်',
                        'descEn' => 'Trigger cash drawer opening without a sale',
                        'descMm' => 'အရောင်းမရှိဘဲ ငွေအံဆွဲကို လက်ဖြင့်ဖွင့်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'viewCostPrice',
                        'labelEn' => 'View Profit / Cost Margin',
                        'labelMm' => 'အမြတ်နှင့် ဝယ်ရင်းစျေး ကြည့်ခွင့်',
                        'descEn' => 'Expose part purchase cost and net margin in POS',
                        'descMm' => 'အရောင်းကောင်တာတွင် ပစ္စည်းဝယ်ရင်းစျေးနှင့် အမြတ်ကို မြင်တွေ့ခွင့်',
                        'isDanger' => true,
                    ],
                ],
            ],
            'inventory' => [
                'titleEn' => 'Inventory & Parts Warehouse',
                'titleMm' => 'စတော့ခ်နှင့် အပိုပစ္စည်း စီမံခန့်ခွဲမှု',
                'icon' => 'fa-solid fa-boxes-stacked text-cyan-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'View Stock Inventory',
                        'labelMm' => 'လက်ကျန်စတော့ခ် ကြည့်ရှုခွင့်',
                        'descEn' => 'Search parts, check quantities and bin racks',
                        'descMm' => 'ပစ္စည်းစာရင်း၊ လက်ကျန်အရေအတွက်နှင့် နေရာစင် ကြည့်ရှုခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'create',
                        'labelEn' => 'Add New Products & Parts',
                        'labelMm' => 'ပစ္စည်းအသစ် ထည့်သွင်းခွင့်',
                        'descEn' => 'Create SKU, barcode, specs, and retail prices',
                        'descMm' => 'ပစ္စည်းကုဒ်၊ ဘားကုဒ်နှင့် ရောင်းစျေးအသစ် သတ်မှတ်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'edit',
                        'labelEn' => 'Edit Part Specs & Prices',
                        'labelMm' => 'ပစ္စည်းအချက်အလက် ပြင်ဆင်ခွင့်',
                        'descEn' => 'Modify product names, retail pricing, and categories',
                        'descMm' => 'ပစ္စည်းအမည်၊ ရောင်းစျေးနှင့် အမျိုးအစား ပြင်ဆင်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'delete',
                        'labelEn' => 'Delete Catalog Items',
                        'labelMm' => 'ပစ္စည်းစာရင်း ဖျက်ပစ်ခွင့်',
                        'descEn' => 'Remove spare parts permanently from database',
                        'descMm' => 'စနစ်ထဲမှ အပိုပစ္စည်းစာရင်းကို အပြီးတိုင် ဖျက်ပစ်ခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'adjustStock',
                        'labelEn' => 'Manual Stock Adjustments',
                        'labelMm' => 'စတော့ခ် အတိုးအလျော့ ချိန်ညှိခွင့်',
                        'descEn' => 'Reconcile physical stock discrepancy and write-offs',
                        'descMm' => 'ရုပ်ပိုင်းဆိုင်ရာ လက်ကျန်နှင့် စာရင်းညှိခြင်းနှင့် ပျက်စီးဆုံးရှုံးမှု စာရင်းသွင်းခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'viewCostPrice',
                        'labelEn' => 'View Landed Cost Prices',
                        'labelMm' => 'အဝယ်ကုန်ကျစရိတ် ကြည့်ခွင့်',
                        'descEn' => 'Display supplier purchase and freight landed cost',
                        'descMm' => 'ကုန်သွင်းသူထံမှ အဝယ်ရင်းစျေးနှင့် ကုန်ကျစရိတ် မြင်တွေ့ခွင့်',
                        'isDanger' => true,
                    ],
                ],
            ],
            'workshop' => [
                'titleEn' => 'Workshop & Job Orders',
                'titleMm' => 'ဝပ်ရှော့နှင့် ပြုပြင်ရေး အလုပ်အမိန့်စာ',
                'icon' => 'fa-solid fa-wrench text-amber-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'View Active Job Cards',
                        'labelMm' => 'အလုပ်အမိန့်စာများ ကြည့်ရှုခွင့်',
                        'descEn' => 'Track vehicle repairs and mechanic assignments',
                        'descMm' => 'ယာဉ်ပြုပြင်မှု အခြေအနေနှင့် တာဝန်ကျဆရာများ ကြည့်ရှုခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'create',
                        'labelEn' => 'Create Repair Job Cards',
                        'labelMm' => 'အလုပ်အမိန့်စာ အသစ်ဖွင့်ခွင့်',
                        'descEn' => 'Register incoming vehicles, customer complaints, initial diagnosis',
                        'descMm' => 'ယာဉ်လက်ခံခြင်း၊ ချွတ်ယွင်းချက်နှင့် စစ်ဆေးချက် မှတ်တမ်းတင်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'edit',
                        'labelEn' => 'Update Repair Progress',
                        'labelMm' => 'ပြုပြင်မှုအခြေအနေ ပြင်ဆင်ခွင့်',
                        'descEn' => 'Update service stages, add labor charges, request parts',
                        'descMm' => 'လုပ်ငန်းအဆင့်၊ လက်ခနှင့် လိုအပ်သော အပိုပစ္စည်းများ ထည့်သွင်းခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'delete',
                        'labelEn' => 'Cancel / Delete Job Cards',
                        'labelMm' => 'အလုပ်အမိန့်စာ ပယ်ဖျက်ခွင့်',
                        'descEn' => 'Discard void or canceled service tickets',
                        'descMm' => 'ပယ်ဖျက်လိုက်သော ဝန်ဆောင်မှုလက်မှတ်များကို ဖျက်ပစ်ခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'assignMechanic',
                        'labelEn' => 'Assign Mechanics & Commissions',
                        'labelMm' => 'ကျွမ်းကျင်ဆရာ တာဝန်ပေးခွင့်',
                        'descEn' => 'Assign lead technician and allocate commission ratios',
                        'descMm' => 'ပြုပြင်မည့်ဆရာ သတ်မှတ်ပေးခြင်းနှင့် ကော်မရှင်ရာခိုင်နှုန်း ခွဲဝေခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'approveJobCard',
                        'labelEn' => 'Final QA & Gate Pass Release',
                        'labelMm' => 'အရည်အသွေးစစ်ဆေးမှုနှင့် ကားထုတ်ခွင့်ပြုချက်',
                        'descEn' => 'Sign off quality inspection and permit vehicle exit',
                        'descMm' => 'စစ်ဆေးမှု ပြီးပြည့်စုံကြောင်း လက်မှတ်ထိုးခြင်းနှင့် ယာဉ်ထုတ်ခွင့်ပြုခြင်း',
                        'isDanger' => false,
                    ],
                ],
            ],
            'finances' => [
                'titleEn' => 'Financials & Credit Approvals',
                'titleMm' => 'ဘဏ္ဍာရေးနှင့် အကြွေးခွင့်ပြုချက်',
                'icon' => 'fa-solid fa-chart-line text-pink-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'View Financial Summaries',
                        'labelMm' => 'ဘဏ္ဍာရေးအနှစ်ချုပ် ကြည့်ရှုခွင့်',
                        'descEn' => 'Access daily turnover, cash drawer float summaries',
                        'descMm' => 'နေ့စဉ် အရောင်းဝင်ငွေနှင့် ငွေစာရင်း အနှစ်ချုပ် ကြည့်ရှုခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'viewProfitLoss',
                        'labelEn' => 'View Net Profit & Loss (P&L)',
                        'labelMm' => 'အမြတ်/အရှုံး စာရင်း အသေးစိတ် ကြည့်ခွင့်',
                        'descEn' => 'Expose net margins, gross profit, and operating overheads',
                        'descMm' => 'လုပ်ငန်းလည်ပတ်မှု အမြတ်၊ အရှုံးနှင့် အသားတင်ဝင်ငွေ စာရင်း ကြည့်ခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'approveCreditLimit',
                        'labelEn' => 'Approve Customer Credit Limits',
                        'labelMm' => 'ဖောက်သည် အကြွေးကန့်သတ်ငွေ ခွင့်ပြုခွင့်',
                        'descEn' => 'Authorize credit sales exceeding default threshold',
                        'descMm' => 'သတ်မှတ်ထားသော ပမာဏထက်ကျော်လွန်သည့် အကြွေးအရောင်းကို အတည်ပြုခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'exportReports',
                        'labelEn' => 'Export Audit & Tax Datasets',
                        'labelMm' => 'စာရင်းစစ် အစီရင်ခံစာများ ထုတ်ယူခွင့်',
                        'descEn' => 'Download Excel / CSV statements of financial transactions',
                        'descMm' => 'ဘဏ္ဍာရေးစာရင်းများနှင့် အခွန်မှတ်တမ်းများကို Excel ဖိုင် ထုတ်ယူခွင့်',
                        'isDanger' => false,
                    ],
                ],
            ],
            'purchases' => [
                'titleEn' => 'Procurement & Vendor Orders',
                'titleMm' => 'အဝယ်နှင့် ကုန်ပစ္စည်းလက်ခံမှု',
                'icon' => 'fa-solid fa-cart-shopping text-blue-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'View Purchase Invoices',
                        'labelMm' => 'အဝယ်ပြေစာများ ကြည့်ရှုခွင့်',
                        'descEn' => 'Browse supplier invoices and pending deliveries',
                        'descMm' => 'ကုန်သွင်းသူ ဘောက်ချာများနှင့် ရောက်ရှိမည့် ပစ္စည်းစာရင်း ကြည့်ရှုခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'create',
                        'labelEn' => 'Create Purchase Orders (PO)',
                        'labelMm' => 'အဝယ်အမိန့်စာ အသစ်ဖွင့်ခွင့်',
                        'descEn' => 'Draft procurement requests to parts distributors',
                        'descMm' => 'အပိုပစ္စည်း ကုမ္ပဏီများထံ ကုန်ပစ္စည်း မှာယူမှုစာရင်း ဖွင့်လှစ်ခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'approvePO',
                        'labelEn' => 'Approve & Settle PO Invoices',
                        'labelMm' => 'အဝယ်ငွေပေးချေမှု အတည်ပြုခွင့်',
                        'descEn' => 'Authorize payment disbursement to vendors',
                        'descMm' => 'ကုန်သွင်းသူထံ ကုန်ကျငွေ ပေးချေမှုကို အတည်ပြုခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'delete',
                        'labelEn' => 'Delete Purchase Records',
                        'labelMm' => 'အဝယ်စာရင်း ပယ်ဖျက်ခွင့်',
                        'descEn' => 'Void supplier procurement entries',
                        'descMm' => 'ကုန်သွင်းစာရင်း မှတ်တမ်းများကို ပယ်ဖျက်ခွင့်',
                        'isDanger' => true,
                    ],
                ],
            ],
            'settings' => [
                'titleEn' => 'System & Security Settings',
                'titleMm' => 'စနစ်နှင့် လုံခြုံရေး ချိန်ညှိချက်များ',
                'icon' => 'fa-solid fa-shield-halved text-purple-400',
                'items' => [
                    [
                        'key' => 'view',
                        'labelEn' => 'Access System Settings',
                        'labelMm' => 'စနစ်ချိန်ညှိချက် ဝင်ရောက်ခွင့်',
                        'descEn' => 'View printers, store details, and company profile',
                        'descMm' => 'ပရင်တာ၊ ဆိုင်အချက်အလက်နှင့် ဆက်တင်များ ကြည့်ရှုခွင့်',
                        'isDanger' => false,
                    ],
                    [
                        'key' => 'manageRoles',
                        'labelEn' => 'Manage Staff Roles & Access',
                        'labelMm' => 'ရာထူးနှင့် လုပ်ပိုင်ခွင့်များ စီမံခွင့်',
                        'descEn' => 'Create custom roles, edit permissions, change PIN codes',
                        'descMm' => 'ရာထူးအသစ်ဖွဲ့စည်းခြင်း၊ လုပ်ပိုင်ခွင့်သတ်မှတ်ခြင်းနှင့် PIN ပြောင်းလဲခွင့်',
                        'isDanger' => true,
                    ],
                    [
                        'key' => 'systemConfig',
                        'labelEn' => 'System Database Configuration',
                        'labelMm' => 'ဒေတာဘေ့စ်နှင့် စနစ်ထိန်းချုပ်ခွင့်',
                        'descEn' => 'Backup data, factory reset, configure API integrations',
                        'descMm' => 'ဒေတာ မိတ္တူကူးခြင်း၊ ပြန်လည်သတ်မှတ်ခြင်းနှင့် စနစ်ထိန်းချုပ်ခွင့်',
                        'isDanger' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate an empty permissions array with all keys set to false.
     *
     * @return array<string, array<string, bool>>
     */
    public function getEmptyTemplate(): array
    {
        $template = [];
        foreach ($this->getCatalog() as $module => $moduleData) {
            $template[$module] = [];
            foreach ($moduleData['items'] as $item) {
                $template[$module][$item['key']] = false;
            }
        }
        return $template;
    }
}
