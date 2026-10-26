-- Read-only preview for the PHP retention job.
-- Set @retention_days to the same value as
-- APPLICANT_REJECTED_RETENTION_DAYS in the application .env file.
-- Do not delete job_applicants directly: the PHP job also removes private
-- uploaded files, applicant documents, and applicant workflow logs.
SET @retention_days = 365;

SELECT ja.applicant_id, ja.updated_at AS rejected_or_last_updated_at
FROM job_applicants AS ja
WHERE ja.status = 'rejected'
  AND ja.updated_at < DATE_SUB(NOW(), INTERVAL @retention_days DAY)
  AND NOT EXISTS (
      SELECT 1
      FROM data_subject_requests AS dsr
      WHERE LOWER(dsr.requester_email) COLLATE utf8mb4_general_ci
            = LOWER(ja.email) COLLATE utf8mb4_general_ci
        AND dsr.status IN ('pending','identity_verification','identity_verified','in_review','on_hold')
  )
  AND NOT EXISTS (
      SELECT 1
      FROM hr_letters AS hl
      WHERE hl.applicant_id = ja.applicant_id
  )
ORDER BY ja.updated_at ASC
LIMIT 100;
