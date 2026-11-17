# Labriqo

**Laboratory Equipment Borrowing & Return Management System**

Borrow Smart. Return on Time.

Labriqo is a university lab inventory and lending project built with plain PHP and MySQL. Students will request equipment, and lab staff will manage inventory, issue equipment, and record returns.

## Phase 1 setup

The current phase contains the four database tables, sample inventory, and the shared PHP connection. Use XAMPP for Windows with PHP 8 and the PDO MySQL extension. XAMPP labels its database service **MySQL**, and the bundled database is MariaDB. If you use standalone MySQL, use 8.0.16 or later so that it enforces the stock constraints.

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Open [phpMyAdmin](http://localhost/phpmyadmin/) and select **Import** from its home page.
3. Choose `database.sql` from the Labriqo folder and select **Import**. The script creates and selects the `labriqo` database.
4. Select `labriqo` in the sidebar. Check that it contains `users`, `categories`, `equipment`, and `borrow_requests`. Open `equipment` and select **Browse** to see five sample items.

[XAMPP for Windows documentation](https://www.apachefriends.org/faq_windows) · [MySQL stock constraint support](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)

### Verify PHP can connect

Open PowerShell in the Labriqo project folder and run the following. Adjust the path to `php.exe` if XAMPP is installed elsewhere.

```powershell
@'
<?php
require 'config/db.php';
$equipmentCount = db()->query('SELECT COUNT(*) FROM equipment')->fetchColumn();
echo "Database connected. Equipment types: {$equipmentCount}", PHP_EOL;
'@ | & 'C:\xampp\php\php.exe'
```

A fresh import prints `Database connected. Equipment types: 5`. The sample inventory contains 42 physical units, with all 42 initially available.

`db()` opens the PDO connection on first use and reuses it for the rest of that page request. It selects UTF-8, uses native prepared statements, and reports database errors as exceptions.

### Troubleshooting

| Error | What to check |
| --- | --- |
| Connection refused | Start **MySQL** in XAMPP. If it uses a custom port, set the `LABRIQO_DB_PORT` environment variable in the environment running PHP. |
| Unknown database `labriqo` | Import `database.sql` into the same database server PHP connects to. |
| Access denied | The connection uses XAMPP's usual local `root` account. If you configured a different user, update `$username`. If your database account has a password, provide it through the `LABRIQO_DB_PASSWORD` environment variable in the environment running PHP. |
| Could not find driver | Run `C:\xampp\php\php.exe -m` and check for `pdo_mysql`. Enable that extension in the active `php.ini` if it is missing. Restart Apache if changing its PHP configuration. |

## Database relationships

- A category can contain many equipment entries. Every equipment entry belongs to one category.
- A student can submit many borrowing requests. Each request belongs to one user and refers to one equipment entry. The application will check that the requester is a signed-in student.
- Foreign keys protect request history. Equipment with past requests can be archived using `is_active`.
- The database enforces unique emails, student IDs, and equipment codes, along with valid stock counts and positive request quantities.

The next phase adds student registration, staff sign-in, role checks, and the shared page layout.
