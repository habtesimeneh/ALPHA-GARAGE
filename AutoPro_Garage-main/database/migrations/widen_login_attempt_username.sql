-- Login identifiers may be email addresses up to users.email's 150-character limit.
ALTER TABLE login_attempts MODIFY username VARCHAR(150) NOT NULL;
