-- Target database: hrms
-- Replace corrupted schedule separators with the requested ASCII dash.
UPDATE shifts
SET shift_name = REPLACE(shift_name, '?', '-')
WHERE shift_name LIKE '%?%';
