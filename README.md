# Labriqo

**Laboratory Equipment Borrowing & Return Management System**

Borrow Smart. Return on Time.

Labriqo replaces a university laboratory's paper borrowing register. Students can find equipment, check stock, and request a loan. Staff manage the inventory, issue equipment, confirm returns, and track overdue loans.

## Run with XAMPP on Windows

Use XAMPP with Apache, PHP 8, and the `pdo_mysql` and `mbstring` extensions. XAMPP's **MySQL** service runs MariaDB. If you use standalone MySQL, use 8.0.16 or later so it enforces the stock constraints.

1. Download the repository or clone it with `git clone https://github.com/vishnu-tharan/Labriqo.git`.
2. In the **XAMPP Control Panel**, start **Apache** and **MySQL**.
3. Open [phpMyAdmin](http://localhost/phpmyadmin/) and select **Import** from its home page. Choose `database.sql` from the repository and run the import. The script creates the `labriqo` database and seeds five equipment types across three categories, with 42 units available.
4. Open PowerShell in the repository folder. Run the commands below to place the app under Apache's document root. Adjust the path if XAMPP is installed elsewhere.

```powershell
$labriqoSite = 'C:\xampp\htdocs\Labriqo'
New-Item -ItemType Directory -Path $labriqoSite -Force | Out-Null
Copy-Item -LiteralPath assets,config,includes,staff,student,index.php,login.php,logout.php,register.php `
    -Destination $labriqoSite -Recurse -Force
```

5. Open [Labriqo](http://localhost/Labriqo/) and select **Register** to create a student account.

[XAMPP for Windows documentation](https://www.apachefriends.org/faq_windows) · [MySQL constraint support](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)

### Create the first staff account

Public registration creates student accounts. Use PowerShell to generate a hash for the staff password. Enter at least 8 characters and at most 72 bytes.

```powershell
$staffPassword = Read-Host 'Staff password' -AsSecureString
$env:LABRIQO_STAFF_PASSWORD = [System.Net.NetworkCredential]::new('', $staffPassword).Password
try {
    @'
<?php
$password = (string) getenv('LABRIQO_STAFF_PASSWORD');
if (mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
    echo 'Use at least 8 characters and at most 72 bytes.', PHP_EOL;
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
'@ | & 'C:\xampp\php\php.exe'
} finally {
    Remove-Item Env:LABRIQO_STAFF_PASSWORD
}
```

In phpMyAdmin, select **labriqo** and open the **SQL** tab. Replace the example name, email, and department with the staff member's details. Replace `PASTE_HASH_HERE` with the password hash printed above.

```sql
INSERT INTO users (name, email, password, department, role)
VALUES ('Lab Staff', 'staff@example.edu', 'PASTE_HASH_HERE', 'Engineering', 'staff');
```

Sign in using that email and the original password. The account opens the staff dashboard.

### Troubleshooting

| Symptom | What to check |
| --- | --- |
| Page returns 404 | Check that `index.php` is inside `C:\xampp\htdocs\Labriqo`. If Apache uses a custom port, include it in the URL. |
| Labriqo is temporarily unavailable | Check that MySQL is running and `labriqo` is visible in phpMyAdmin. Review the PHP error log for the underlying error. |
| Database connection refused or access denied | `config/db.php` defaults to the local `root` account and port `3306`. Set `LABRIQO_DB_PORT` or `LABRIQO_DB_PASSWORD` in the environment running PHP if your settings differ. Edit `$username` in `config/db.php` for a different account. |
| PHP reports a missing extension | Run `C:\xampp\php\php.exe -m` to check for `pdo_mysql` and `mbstring`. Enable the missing extension in XAMPP's `php.ini` and restart Apache. |

## Borrow and return equipment

1. **Student** · Open **Equipment**. Search by name or code, filter by category, and open an item. Enter a quantity, preferred date, and reason, then submit. **My requests** shows pending requests, active loans, and history. A student can cancel their own pending requests.
2. **Staff** · Open **Requests**. Review the student and equipment details. Enter a return due date and select **Approve and issue** when handing over the equipment, or choose **Reject**.
3. **Staff** · Open **Returns** when equipment comes back. Enter the full returned quantity and select **Confirm return**. Use **Overdue only** to find outstanding loans past their due date. Use **View return history** to find completed returns.

Staff use **Equipment** to add, edit, or remove inventory. Quantity edits account for checked-out units. Pending requests and active loans block removal. Equipment with completed history is archived and stays in borrowing records.

The student dashboard shows equipment types in stock and the student's own requests and loans. The staff dashboard shows active inventory totals and recent returns. Pending and overdue totals count records. Borrowed totals count units.

## Code and database

| Location | Purpose |
| --- | --- |
| Root PHP pages | Entry point, sign-in, student registration, and sign-out |
| `student/` and `staff/` | Pages for each role, including dashboards |
| `includes/` | Shared page layout, sessions, form tokens, and access checks |
| `config/db.php` | Reusable PDO connection with prepared statements |
| `assets/css/style.css` | Shared responsive layout and styles |
| `database.sql` | Schema and sample inventory |

`users` stores students and staff with unique emails and hashed passwords. `categories` groups equipment. Each `borrow_requests` row links a user to an equipment record and keeps its status and dates. Foreign keys preserve the links used by history.

Submitting, cancelling, and rejecting a request leave stock unchanged. Approval and return lock the equipment row and update stock and request status in a single transaction. Due dates and overdue counts use the database server's date.

## Test the project

Start with the sample inventory in a fresh local database. Use separate browser sessions for staff and students.

| Test | Expected result |
| --- | --- |
| Register a student, then try the same email or student ID again | Registration rejects the duplicate. The student can sign in and edit their profile. |
| Try a staff page while signed in as a student, then sign out and reopen the student dashboard | The staff page denies access. The student dashboard asks you to sign in again. |
| Search for `TST-001`, then filter by Test Instruments | The Digital Multimeter appears with eight available units on a fresh import. |
| Add equipment as staff, then try a duplicate code or negative quantity | Valid equipment appears in the catalog. Invalid changes show an error. |
| Request two multimeters | The request is **Pending** and eight units remain available. |
| Set an item to one available unit and request three, or enter a past preferred date | The request is rejected. |
| Cancel or reject a pending request | Its status changes, and the available quantity stays the same. |
| Approve and issue the request for two multimeters | It becomes **Borrowed**, its due date appears, and available stock falls from eight to six. |
| Submit two requests for four units of a five-unit item, then approve from two staff sessions | Only one issues equipment. The other stays pending and one unit remains available. |
| Verify a loan due today and one due yesterday in the overdue view | Only the outstanding loan due yesterday is overdue. The view shows one day overdue. |
| Enter one unit for a two-unit loan, then confirm with two | The partial return is rejected. The full return restores stock, changes the status to **Returned**, and appears in history. A repeat submission leaves stock unchanged. |
| Remove equipment with past requests after all active loans and pending requests are resolved | Staff see it as **Archived**. It is hidden from the student catalog, and its records are still in history. |
| Open both dashboards and a request table on a narrow screen | Cards reflow and wide tables scroll inside their own area. |

To demonstrate the overdue case immediately, use phpMyAdmin in a disposable test database after issuing a loan. Set its `issue_date` to two days ago and its `due_date` to yesterday. Leave another active loan due today for comparison.


## Rejection reasons and categories

Staff enter a reason when rejecting a pending request. The reason appears alongside the rejected status for staff and the student who submitted it. Earlier rejections without a reason show **No reason recorded**.

Open **Equipment**, then **Manage categories** to add or rename categories. Equipment keeps the same category link when a category is renamed.

For an existing installation created before these additions, run the following once in phpMyAdmin's SQL tab with the **labriqo** database selected. A fresh import of the updated database.sql already contains the field.

    ALTER TABLE borrow_requests
    ADD COLUMN rejection_reason VARCHAR(1000) DEFAULT NULL AFTER reason;

Check that rejecting without a reason shows an error, a valid rejection displays its reason to the correct student, and stock stays the same. Try adding a duplicate category name, then rename an existing category and confirm its equipment appears under the new name.


## Change password

Students open **My profile** and staff open **Dashboard** to find **Change password**. Enter the current password, a new password of at least 8 characters, and the new password again to confirm it. The form supports passwords up to 72 bytes.

After saving, use the new password for your next sign-in. If you receive an incorrect-current-password message, check the password you currently use to sign in.

To test, try an incorrect current password and mismatched new passwords first. Then save a valid new password, sign out, and check that the old password fails while the new password signs you in.

## Return reminders

The student dashboard shows **Returns due soon** when the signed-in student has an active loan due today or within the next three days. Reminders list the equipment, quantity, and due date, with the earliest due date first. They use the same database date as the overdue view.

To test, issue loans due today, tomorrow, in three days, and in four days. The first three appear in the reminder list. A returned loan drops out of the list, and each student sees only their own loans.

## CSV downloads

Staff can choose **Download CSV** on **Equipment** or **Requests**. The download uses the search and filters selected in that form. Choose **All statuses** on Requests to include the full borrowing history. A search with no matching records produces a file containing the column headings.

Inventory exports include quantities and the active/archive flag. Borrowing exports include student details, quantities, reasons, statuses, and dates. User-entered values that could be interpreted as spreadsheet formulas receive a leading apostrophe in the CSV.

To test, filter inventory to one equipment code and download it. Check that only that item appears. Then filter requests by a student ID and status and check that the download matches those filters.
