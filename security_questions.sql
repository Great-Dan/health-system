ALTER TABLE users
    ADD COLUMN security_question_1 VARCHAR(255) DEFAULT NULL,
    ADD COLUMN security_answer_1_hash VARCHAR(255) DEFAULT NULL,
    ADD COLUMN security_question_2 VARCHAR(255) DEFAULT NULL,
    ADD COLUMN security_answer_2_hash VARCHAR(255) DEFAULT NULL;