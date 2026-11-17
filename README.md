# Labriqo

**Laboratory Equipment Borrowing & Return Management System**

Borrow Smart. Return on Time.

Labriqo is a university lab inventory and lending project built with plain PHP and MySQL. Students will request equipment, and lab staff will manage inventory, issue equipment, and record returns.

## Phase 1 setup

Labriqo uses four database tables, sample inventory, and a shared PHP connection. Use XAMPP for Windows with PHP 8 and the `pdo_mysql` and `mbstring` extensions. XAMPP labels its database service **MySQL**, and the bundled database is MariaDB. If you use standalone MySQL, use 8.0.16 or later so that it enforces the stock constraints.

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

## Phase 2 authentication

Phase 2 uses the database from Phase 1.

1. Create `C:\xampp\htdocs\Labriqo`.
2. Copy `assets`, `config`, `includes`, `student`, `staff`, and the four root PHP files into that folder.
3. With Apache and MySQL running, open [Labriqo](http://localhost/Labriqo/) and choose **Register** to create a student account.
4. Sign in. The student dashboard links to **My profile**, where you can change your name, email, and department. Use the updated email on your next sign-in.

To run the app from the project folder without copying files, leave MySQL running and use the PHP development server.

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000
```

Open [Labriqo on the development server](http://127.0.0.1:8000/). Press **Ctrl+C** in PowerShell to stop the development server.

### Set up a staff account

Create staff accounts through the database. Public registration always creates student accounts.

In PowerShell, run the following and enter a password of at least 8 characters and at most 72 bytes. Copy the hash printed by PHP.

```powershell
$staffPassword = Read-Host 'Staff password' -AsSecureString
$env:LABRIQO_STAFF_PASSWORD = [System.Net.NetworkCredential]::new('', $staffPassword).Password
try {
    @'
<?php
$password = (string) getenv('LABRIQO_STAFF_PASSWORD');
if (mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
    exit("Use at least 8 characters and at most 72 bytes." . PHP_EOL);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
'@ | & 'C:\xampp\php\php.exe'
} finally {
    Remove-Item Env:LABRIQO_STAFF_PASSWORD
}
```

In phpMyAdmin, choose **labriqo**, open the **SQL** tab, and run the insert below. Replace the example name, email, department, and hash with the staff member's details and the copied hash.

```sql
INSERT INTO users (name, email, password, department, role)
VALUES ('Lab Staff', 'staff@example.edu', 'PASTE_HASH_HERE', 'Engineering', 'staff');
```

Sign in on the same page as students. The account's database role determines which dashboard opens.

### Test authentication

| Try | Expected result |
| --- | --- |
| Register two students with the same email or student ID | The second registration shows a duplicate-account error. |
| Submit mismatched passwords or sign in with the wrong password | The form shows an error and keeps you signed out. |
| Visit `staff/dashboard.php` as a student, or `student/profile.php` as staff | The server returns access denied (403). |
| Change your student profile and reload the page | The updated details are saved. An email already in use is rejected. |
| Sign out and try reopening either dashboard | The dashboard sends you back to sign-in. |

If Apache returns a 404, check the folder name under `htdocs` and the address in your browser. If Labriqo reports that it is temporarily unavailable, check that MySQL is running, the database is imported, and the connection test from Phase 1 succeeds.

`includes/auth.php` starts the session and provides the role and form checks used by the pages. The session remembers the signed-in user's ID. Protected pages load that user's account and check its current role. Every form that changes state checks a token tied to the session before handling the submission. A successful sign-in renews the session ID, and signing out clears both the session and its cookie.

## Phase 3 equipment

Copy the updated application files into `C:\xampp\htdocs\Labriqo` if you use Apache. Start Apache and MySQL, then sign in and open **Equipment**. The equipment pages use the database and sample categories from Phase 1.

- Students can search by name or equipment code, filter by category, and view stock, storage locations, and descriptions. Archived equipment is hidden from the student catalog.
- Staff can add equipment, edit the details, set the category and total quantity, and remove items through a confirmation form. The image URL is optional. Accepted URLs use HTTP or HTTPS.
- New equipment starts with all units available. An edit reads the latest stock before saving and keeps checked-out units accounted for. The database applies the new total and available stock together.
- An item with a pending request or active loan stays in the inventory. Removing an item with past requests archives it to preserve the borrowing history. An item with no requests can be deleted.

### Test equipment

| Try | Expected result |
| --- | --- |
| Search for `tst-001` as a student and open its details | The Digital Multimeter appears with its description, location, and available quantity. |
| Filter by **Networking** | The catalog shows equipment from that category. |
| Add an item with a total quantity of 3 as staff | It appears in the inventory and student catalog with 3 available units. |
| Try a duplicate code, missing category, or negative quantity | The form explains the error and leaves inventory unchanged. |
| Set an item's total to 0 when all units are in stock | The catalog keeps the item and marks it **Unavailable**. |
| Confirm removal of a new item with no requests | The item disappears from the inventory and catalog. |
| Visit `staff/equipment.php` as a student | The server returns access denied (403). |

## Phase 4 student borrowing

Copy the latest application files into `C:\xampp\htdocs\Labriqo` if you use Apache. Sign in as a student, open **Equipment**, select an item, and submit the **Request equipment** form with a quantity, reason, and preferred borrowing date. Dates use the database server's local date.

Open **My requests** to see pending requests, current loans, and borrowing history. The table shows issue, due, and return dates when they are recorded. Use **Cancel** on a pending request to cancel it.

The equipment page checks the latest available stock before saving a pending request. It locks the item briefly so staff stock edits and removals cannot interrupt that check. The requests page cancels only a pending request belonging to the signed-in student. Submitting and cancelling keep the available quantity unchanged. Staff approval and returns are Phase 5.

### Test student borrowing

| Try | Expected result |
| --- | --- |
| Request two Digital Multimeters with a reason and today's date | **My requests** shows a pending request for two units. The catalog keeps the same available quantity. |
| Request more units than are available, or use zero or a decimal | The form shows a quantity error. |
| Omit the reason or use a past date | The form shows a validation error. |
| Cancel a pending request and refresh | The request stays in history as **Cancelled**, and stock stays the same. |
| Sign in as a different student | **My requests** shows only the signed-in student's records. |

If the request form is missing, check the available quantity on the equipment page. If a request fails after the page has been open for a while, refresh to see the latest stock.
