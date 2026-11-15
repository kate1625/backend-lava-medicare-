-- Apply once to an existing MediCare+ database if migrations are not being run.
USE medicare_plus;

ALTER TABLE dentist_schedules
  MODIFY COLUMN day_of_week TINYINT NULL,
  MODIFY COLUMN start_time TIME NULL,
  MODIFY COLUMN end_time TIME NULL;

SET @follow_up_time_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'treatment_records'
    AND column_name = 'follow_up_time'
);
SET @follow_up_time_sql = IF(
  @follow_up_time_exists = 0,
  'ALTER TABLE treatment_records ADD COLUMN follow_up_time TIME NULL AFTER follow_up_date',
  'SELECT 1'
);
PREPARE follow_up_time_statement FROM @follow_up_time_sql;
EXECUTE follow_up_time_statement;
DEALLOCATE PREPARE follow_up_time_statement;

SET @reply_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'clinic_messages'
    AND column_name = 'reply_to_message_id'
);
SET @reply_column_sql = IF(
  @reply_column_exists = 0,
  'ALTER TABLE clinic_messages ADD COLUMN reply_to_message_id BIGINT NULL AFTER body',
  'SELECT 1'
);
PREPARE reply_column_statement FROM @reply_column_sql;
EXECUTE reply_column_statement;
DEALLOCATE PREPARE reply_column_statement;

SET @reply_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'clinic_messages'
    AND index_name = 'idx_clinic_messages_reply_to'
);
SET @reply_index_sql = IF(
  @reply_index_exists = 0,
  'ALTER TABLE clinic_messages ADD INDEX idx_clinic_messages_reply_to (reply_to_message_id)',
  'SELECT 1'
);
PREPARE reply_index_statement FROM @reply_index_sql;
EXECUTE reply_index_statement;
DEALLOCATE PREPARE reply_index_statement;

SET @reply_constraint_exists = (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'clinic_messages'
    AND constraint_name = 'fk_clinic_messages_reply_to'
);
SET @reply_constraint_sql = IF(
  @reply_constraint_exists = 0,
  'ALTER TABLE clinic_messages ADD CONSTRAINT fk_clinic_messages_reply_to FOREIGN KEY (reply_to_message_id) REFERENCES clinic_messages(message_id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE reply_constraint_statement FROM @reply_constraint_sql;
EXECUTE reply_constraint_statement;
DEALLOCATE PREPARE reply_constraint_statement;
