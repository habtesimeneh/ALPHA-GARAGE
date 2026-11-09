-- Contact Inquiries Table
CREATE TABLE IF NOT EXISTS contact_inquiries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NULL,
  channel VARCHAR(40) NOT NULL DEFAULT 'Web Contact Form',
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  ip_address VARCHAR(45) NULL,
  status ENUM('new','in_progress','resolved') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

