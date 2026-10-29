<?php

namespace App\Domains\Workshop\Actions;

use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Domains\Workshop\Models\WorkOrder;

class CalculateMechanicCommissionAction
{
    /**
     * Compute and record labor commission for assigned technician.
     */
    public function execute(WorkOrder $workOrder): ?MechanicCommission
    {
        if (!$workOrder->assigned_mechanic_id || (float) $workOrder->labor_fee <= 0) {
            return null;
        }

        $mechanic = Mechanic::find($workOrder->assigned_mechanic_id);
        if (!$mechanic) {
            return null;
        }

        $rate = (float) $mechanic->commission_rate;
        $earned = round(((float) $workOrder->labor_fee * ($rate / 100)), 2);

        return MechanicCommission::create([
            'mechanic_id' => $mechanic->id,
            'work_order_id' => $workOrder->id,
            'labor_amount' => $workOrder->labor_fee,
            'commission_rate' => $rate,
            'earned_amount' => $earned,
            'status' => 'Pending',
        ]);
    }
}
