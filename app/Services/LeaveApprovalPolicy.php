<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class LeaveApprovalPolicy
{
    public const RANKS = ['employee' => 1, 'accountant' => 2, 'supervisor' => 3, 'hr' => 4, 'hr_manager' => 5, 'admin' => 6];

    public static function rankSql(): string
    {
        $sql = 'MAX(CASE role';
        foreach (self::RANKS as $role => $rank) {
            $sql .= " WHEN '$role' THEN $rank";
        }

        return $sql.' ELSE 6 END)';
    }

    public static function scope(Builder $query, object $actor, string $teams): Builder
    {
        if ($actor->role === 'admin') {
            return $query;
        }
        if ($actor->employee_id) {
            $query->where('lr.employee_id', '<>', $actor->employee_id);
        }
        if ($actor->role === 'hr_manager') {
            return $query->where('requester_tiers.tier', self::RANKS['hr']);
        }
        if ($actor->role === 'hr') {
            return $query->where(function ($scope) use ($teams) {
                $scope->where('requester_tiers.tier', self::RANKS['supervisor'])->orWhere(function ($regular) use ($teams) {
                    $regular->whereRaw('COALESCE(requester_tiers.tier, 0) <= 1')->whereNotExists(function ($team) use ($teams) {
                        $team->selectRaw('1')->from($teams.' as tm')->whereColumn('tm.member_employee_id', 'lr.employee_id');
                    });
                });
            });
        }
        if ($actor->role === 'supervisor' && $actor->employee_id) {
            return $query->whereExists(function ($team) use ($teams, $actor) {
                $team->selectRaw('1')->from($teams.' as tm')->whereColumn('tm.member_employee_id', 'lr.employee_id')->where('tm.supervisor_employee_id', $actor->employee_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public static function allows(object $actor, int $employee, array $roles, ?int $supervisor): bool
    {
        if ($actor->role === 'admin') {
            return true;
        }
        if ((int) $actor->employee_id === $employee) {
            return false;
        }
        $rank = max([0, ...array_map(fn ($role) => self::RANKS[$role] ?? 6, $roles)]);

        return match ($actor->role) {
            'hr_manager' => $rank === self::RANKS['hr'],
            'hr' => $rank === self::RANKS['supervisor'] || ($rank <= 1 && $supervisor === null),
            'supervisor' => $actor->employee_id && $supervisor === (int) $actor->employee_id,
            default => false,
        };
    }
}
