// ============================================================
// gov_contributions.js — 2026 government contribution & withholding
// tax rates (SSS, PhilHealth, Pag-IBIG, flat withholding tax) for
// Philippine payroll. This is the SINGLE SOURCE for these rates —
// if the law/circular changes next year, edit only here; no need
// to touch payroll.js computation logic.
//
// Sources / basis:
//   SSS       — RA 11199, SSS Circular 2024-006 (effective Jan 2025,
//               15% total rate, MSC floor P5,000 / ceiling P35,000)
//   PhilHealth— RA 11223 (UHC Law), 5% flat rate, floor P10,000 /
//               ceiling P100,000, 2.5% employee / 2.5% employer
//   Pag-IBIG  — HDMF Circular 460 (effective Feb 2024), MFS cap
//               P10,000, 1%/2% (<=1,500) or 2%/2% (>1,500)
//   Tax       — BIR Revised Withholding Tax Table, semi-monthly pay
//               frequency column (RR 11-2018 Annex E, implementing
//               RA 10963 / TRAIN Law), effective Jan 1, 2023 and
//               still in force for 2026. Item 14 (Phase 5 audit) —
//               replaces the earlier flat 12% company-policy rate.
//
// IMPORTANT: These are EMPLOYEE-SHARE ONLY computations (the amount
// deducted from pay). Employer counterpart shares (SSS 10%+EC,
// PhilHealth 2.5%, Pag-IBIG 2%) are the employer's own remittance
// liability and are not included here.
// ============================================================

const GOV_CONTRIBUTION_CONFIG = {
  sss: {
    minMSC: 5000,
    maxMSC: 35000,
    step: 500,
    employeeRate: 0.05, // 5% of MSC
  },
  philhealth: {
    floor: 10000,
    ceiling: 100000,
    employeeRate: 0.025, // 2.5% of monthly basic salary
  },
  pagibig: {
    lowSalaryThreshold: 1500,
    lowSalaryEmployeeRate: 0.01,  // 1% when <= P1,500
    normalEmployeeRate: 0.02,     // 2% when > P1,500
    mfsCap: 10000,                // Maximum Fund Salary cap
  },
};

// BIR Revised Withholding Tax Table — SEMI-MONTHLY pay frequency
// (RR 11-2018 Annex E, TRAIN Law, RA 10963). Each row is
// [floor, base tax at floor, rate on excess over floor]. The row that
// applies is the last one whose floor is <= the semi-monthly taxable
// compensation. These are BIR's own printed peso figures — not a
// division of the annual/monthly table (BIR rounds each bracket
// boundary and accumulates tax across the rounded boundaries, so
// dividing the monthly/annual table by 2/24 lands a few centavos off).
const BIR_WITHHOLDING_SEMI_MONTHLY_TABLE = [
  { floor: 0,      base: 0,        rate: 0.00 },
  { floor: 10417,  base: 0,        rate: 0.15 },
  { floor: 16667,  base: 937.50,   rate: 0.20 },
  { floor: 33333,  base: 4270.70,  rate: 0.25 },
  { floor: 83333,  base: 16770.70, rate: 0.30 },
  { floor: 333333, base: 91770.70, rate: 0.35 },
];

/**
 * Snap a monthly basic salary to its SSS Monthly Salary Credit (MSC)
 * bracket. Simplified P500-step model (matches the official table's
 * bracket boundaries closely enough for payroll estimation purposes).
 */
function computeSSSEmployeeShare(monthlySalary) {
  const cfg = GOV_CONTRIBUTION_CONFIG.sss;
  const salary = Math.max(0, monthlySalary || 0);
  let msc = Math.floor(salary / cfg.step) * cfg.step;
  msc = Math.min(Math.max(msc, cfg.minMSC), cfg.maxMSC);
  return round2(msc * cfg.employeeRate);
}

function computePhilHealthEmployeeShare(monthlySalary) {
  const cfg = GOV_CONTRIBUTION_CONFIG.philhealth;
  const salary = Math.max(0, monthlySalary || 0);
  const base = Math.min(Math.max(salary, cfg.floor), cfg.ceiling);
  return round2(base * cfg.employeeRate);
}

function computePagIbigEmployeeShare(monthlySalary) {
  const cfg = GOV_CONTRIBUTION_CONFIG.pagibig;
  const salary = Math.max(0, monthlySalary || 0);
  const base = Math.min(salary, cfg.mfsCap);
  const rate = salary <= cfg.lowSalaryThreshold ? cfg.lowSalaryEmployeeRate : cfg.normalEmployeeRate;
  return round2(base * rate);
}

/**
 * Compute withholding tax for ONE semi-monthly cutoff, given the
 * period's taxable compensation (gross pay for the cutoff MINUS that
 * cutoff's SSS/PhilHealth/Pag-IBIG employee shares). Looks up the BIR
 * semi-monthly Revised Withholding Tax Table bracket (Item 14).
 */
function computeWithholdingTaxSemiMonthly(taxableCompensation) {
  const income = Math.max(0, taxableCompensation || 0);
  // Table is ordered by ascending floor — walk backwards to find the
  // last (highest) bracket whose floor the income has reached.
  let bracket = BIR_WITHHOLDING_SEMI_MONTHLY_TABLE[0];
  for (const row of BIR_WITHHOLDING_SEMI_MONTHLY_TABLE) {
    if (income >= row.floor) {
      bracket = row;
    } else {
      break;
    }
  }
  const excess = income - bracket.floor;
  return round2(bracket.base + excess * bracket.rate);
}

/**
 * Is the given period-start date (YYYY-MM-DD) the FIRST cutoff of its
 * month (1st–15th)? Government contributions should only be deducted
 * ONCE per month — on the 1st cutoff — never again on the 2nd cutoff
 * (16th–end of month) of the same month.
 */
function isFirstCutoffOfMonth(periodStartISO) {
  if (!periodStartISO || typeof periodStartISO !== 'string') return true;
  const parts = periodStartISO.split('-');
  const day = parseInt(parts[2], 10);
  return !isNaN(day) ? day <= 15 : true;
}

/**
 * Convenience: given an employee's full MONTHLY basic salary, return
 * the SSS/PhilHealth/Pag-IBIG employee shares to deduct THIS cutoff.
 * These government contributions are based on monthly compensation
 * brackets, but are only ever deducted ONCE per month (on the 1st
 * cutoff, 1st–15th) — the 2nd cutoff (16th–end) gets 0 for all three,
 * since these should only be deducted once per month.
 */
function computeGovDeductionsForCutoff(monthlyBasicSalary, periodStartISO) {
  if (!isFirstCutoffOfMonth(periodStartISO)) {
    return { sss: 0, phic: 0, pagibig: 0 };
  }
  const sssMonthly = computeSSSEmployeeShare(monthlyBasicSalary);
  const phicMonthly = computePhilHealthEmployeeShare(monthlyBasicSalary);
  const pagibigMonthly = computePagIbigEmployeeShare(monthlyBasicSalary);
  return {
    sss: round2(sssMonthly),
    phic: round2(phicMonthly),
    pagibig: round2(pagibigMonthly),
  };
}

function round2(n) {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}