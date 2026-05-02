# Artwork Approval Hub (ArtFlow)

A production-ready artwork approval system optimized for shared hosting.

## Features
- **Designer Dashboard**: Manage projects, upload versions, and track activity.
- **Client Portal**: Secure token-based access (no login required).
- **Interactive Pins**: Click anywhere on the artwork to place a comment pin.
- **File Support**: PDF (via PDF.js), JPG, PNG, AI, CDR.
- **Notifications**: Real-time AJAX polling for new comments/approvals.
- **Modern UI**: Glassmorphism design with smooth animations.

## Setup Instructions

1. **Database Setup**:
   - Create a new MySQL database (e.g., `artwork_system`).
   - Import `database.sql` into your database.

2. **Configuration**:
   - Edit `config.php` and update the database credentials:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'artwork_system');
     define('DB_USER', 'your_username');
     define('DB_PASS', 'your_password');
     ```
   - Update `BASE_URL` to match your installation path.

3. **Folder Permissions**:
   - Ensure the `uploads/projects/` directory is writable by the web server (chmod 755 or 777).

4. **Login**:
   - Default Email: `designer@example.com`
   - Default Password: `admin123`

## Security Notes
- Input is sanitized using `htmlspecialchars`.
- Database interactions use PDO prepared statements.
- Client links use cryptographically secure 32-character tokens.
- Password hashing using `password_hash()` (PHP default).

## Technologies Used
- PHP 8.x (OOP, PDO)
- MySQL
- Vanilla JavaScript
- CSS3 (Glassmorphism, Flexbox, Grid)
- FontAwesome 6.4
- PDF.js (for browser PDF rendering)
