<?php
function payrollSubmissionTransition($currentStatus, $actorRole) {
    if ($currentStatus !== 'draft' || !in_array($actorRole, ['hr', 'hr_manager'], true)) {
        return null;
    }
    return 'pending';
}

function payrollApprovalTransition($currentStatus, $action, $actorRole) {
    if ($currentStatus !== 'pending' || !in_array($actorRole, ['finance', 'admin'], true)) {
        return null;
    }

    if ($action === 'approve') {
        return 'approved';
    }
    if ($action === 'reject') {
        return 'rejected';
    }
    return null;
}

function payrollCanRelease($approvalStatus, $paymentStatus, $actorRole) {
    return in_array($actorRole, ['finance', 'admin'], true)
        && $approvalStatus === 'approved'
        && $paymentStatus === 'unpaid';
}

function payrollIsReleased($approvalStatus, $paymentStatus) {
    return $paymentStatus === 'paid' && in_array($approvalStatus, ['approved', 'n/a'], true);
}
