# Labriqo

**Laboratory Equipment Borrowing & Return Management System**

Borrow Smart. Return on Time.

Labriqo replaces a university laboratory's paper borrowing register. Students can find equipment, check stock, and request a loan. Staff manage the inventory, issue equipment, confirm returns, and track overdue loans.

**Live demo** · [Open Labriqo](https://labriqo.freepage.cc)

## Screenshots

These screenshots use fictional student and staff accounts in a University of Vavuniya lab demo.

### Sign in

Students and staff use the same sign-in page.

![Labriqo sign-in page](screenshots/01-sign-in.png)

<details>
<summary>Student screens</summary>

**Registration** — Students enter their details and wait for staff approval.

![Student registration form](screenshots/02-student-registration.png)

**Dashboard** — Students see their requests, active loans, and overdue alerts.

![Vishnu's student dashboard with an overdue alert](screenshots/03-student-dashboard.png)

**My profile** — Students can update their details and change their password.

![Vishnu's student profile and password form](screenshots/04-student-profile.png)

**My requests** — Students can check request status and read rejection reasons.

![Vishnu's request history with a rejection reason](screenshots/05-student-request-history.png)

**Equipment catalog** — Students can search and filter equipment by category or availability.

![Equipment catalog with search and availability filters](screenshots/06-equipment-catalog.png)

**Borrow request** — Thaksi enters a quantity, a date, and a reason to borrow a digital multimeter.

![Thaksi's digital multimeter request form](screenshots/07-borrow-request-form.png)

</details>

<details>
<summary>Staff screens</summary>

**Dashboard** — Staff can see overdue loans, low stock, and student accounts awaiting approval.

![Staff dashboard with low stock and account approvals](screenshots/08-staff-dashboard.png)

**Inventory** — Staff can check stock and manage equipment.

![Staff equipment inventory with stock counts](screenshots/09-equipment-inventory.png)

**Categories** — Staff can add and rename equipment categories.

![Staff category management page](screenshots/10-staff-categories.png)

**Pending requests** — Staff can see requests from Tharan and Thaksi in the same queue.

![Pending borrowing requests from Tharan and Thaksi](screenshots/11-staff-pending-requests.png)

**Request review** — Staff can set a due date, issue equipment, or reject the request with a reason. They can also export filtered requests to CSV.

![Staff review of Thaksi's multimeter request](screenshots/12-staff-request-review.png)

**Return condition** — Staff mark Thaksi's multimeter as needing inspection and add a short note.

![Return form with Needs inspection selected](screenshots/13-return-condition-check.png)

**Borrowing slip** — The slip shows the issue details and the recorded return condition.

![Borrowing slip showing the multimeter return and inspection note](screenshots/14-borrowing-and-return-slip.png)

**My profile** — Staff can view their account details and change their password.

![Staff profile and password form](screenshots/15-staff-profile.png)

</details>

## Run with XAMPP on Windows

Use XAMPP with Apache, PHP 8, and the `pdo_mysql` and `mbstring` extensions. XAMPP's **MySQL** service runs MariaDB. If you use standalone MySQL, use 8.0.16 or later so it enforces the stock constraints.

1. Download the repository or clone it with `git clone https://github.com/vishnu-tharan/Labriqo.git`.
2. In the **XAMPP Control Panel**, start **Apache** and **MySQL**.
3. Open [phpMyAdmin](http://localhost/phpmyadmin/) and select **Import** from its home page. Choose `database.sql` from the repository and run the import. The script creates the `labriqo` database and seeds five equipment types across three categories, with 42 units available.
4. If the project is already in C:\xampp\htdocs\Labriqo, continue to step 5. Otherwise, open PowerShell in the repository folder and run the commands below to place the app under Apache's document root. Adjust the path if XAMPP is installed elsewhere.

```powershell
$labriqoSite = 'C:\xampp\htdocs\Labriqo'
New-Item -ItemType Directory -Path $labriqoSite -Force | Out-Null
Copy-Item -LiteralPath assets,config,includes,staff,student,index.php,login.php,logout.php,register.php,.htaccess `
    -Destination $labriqoSite -Recurse -Force
```

5. Create the dedicated database login using the setup below, then open [Labriqo](http://localhost/Labriqo/) and select **Register** to create a student account. Staff verify the student ID and approve the registration from **Dashboard → Student account approvals** before the student signs in.

[XAMPP for Windows documentation](https://www.apachefriends.org/faq_windows) · [MySQL constraint support](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)

### Set up the database login

Use an administrative account in phpMyAdmin to run the following SQL. Replace the placeholder with a long random password using letters and digits.

    CREATE USER 'labriqo_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_RANDOM_PASSWORD';
    GRANT SELECT, INSERT, UPDATE, DELETE ON labriqo.* TO 'labriqo_app'@'127.0.0.1';

Save the following as C:\xampp\labriqo-db.local.php, above the htdocs folder. Use the same password here. Keep this file private.

    <?php
    return [
        'username' => 'labriqo_app',
        'password' => 'REPLACE_WITH_A_RANDOM_PASSWORD',
    ];

You can also supply LABRIQO_DB_USER and LABRIQO_DB_PASSWORD through the environment running PHP. Use phpMyAdmin with an administrative account for schema upgrades.

### Create the first staff account

Public registration creates student accounts. Use PowerShell to generate a hash for the staff password. Use a strong password of at least 15 characters and at most 72 bytes.

```powershell
$staffPassword = Read-Host 'Staff password' -AsSecureString
$env:LABRIQO_STAFF_PASSWORD = [System.Net.NetworkCredential]::new('', $staffPassword).Password
try {
    @'
<?php
$password = (string) getenv('LABRIQO_STAFF_PASSWORD');
if (mb_strlen($password, 'UTF-8') < 15 || strlen($password) > 72) {
    echo 'Use at least 15 characters and at most 72 bytes.', PHP_EOL;
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
| Database connection refused or access denied | `config/db.php` uses port `3306` by default. Check the dedicated database account and its password file, or check both environment variables. Set `LABRIQO_DB_PORT` for a different port. |
| PHP reports a missing extension | Run `C:\xampp\php\php.exe -m` to check for `pdo_mysql` and `mbstring`. Enable the missing extension in XAMPP's `php.ini` and restart Apache. |

## Borrow and return equipment

1. **Student** · Open **Equipment**. Search by name or code, filter by category, and open an item. Enter a quantity, preferred date, and reason, then submit. **My requests** shows pending requests, active loans, and history. A student can cancel their own pending requests.
2. **Staff** · Open **Requests**. Review the student and equipment details. Enter a return due date and select **Approve and issue** when handing over the equipment, or choose **Reject**.
3. **Staff** · Open **Returns** when equipment comes back. Enter the number of units, choose an outcome, and select **Record outcome**. Repeat as needed until all issued units are accounted for. Use **Overdue only** to find outstanding loans past their due date. Use **View return history** to find completed returns.

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
| Register a student, try signing in, and have staff verify and approve the account | Sign-in is blocked until approval. Registration rejects duplicate emails and student IDs. |
| Try a staff page while signed in as a student, then sign out and reopen the student dashboard | The staff page denies access. The student dashboard asks you to sign in again. |
| Search for `TST-001`, then filter by Test Instruments | The Digital Multimeter appears with eight available units on a fresh import. |
| Add equipment as staff, then try a duplicate code or negative quantity | Valid equipment appears in the catalog. Invalid changes show an error. |
| Request two multimeters | The request is **Pending** and eight units remain available. |
| Set an item to one available unit and request three, or enter a past preferred date | The request is rejected. |
| Cancel or reject a pending request | Its status changes, and the available quantity stays the same. |
| Approve and issue the request for two multimeters | It becomes **Borrowed**, its due date appears, and available stock falls from eight to six. |
| Use two different student accounts to request four units each of a five-unit item, then approve from two staff sessions | Only one issues equipment. The other stays pending and one unit remains available. |
| Verify a loan due today and one due yesterday in the overdue view | Only the outstanding loan due yesterday is overdue. The view shows one day overdue. |
| Record one unit for a two-unit loan, then record the remaining unit | Stock increases by one at each return. The loan stays open after the first return and closes after the second. Resubmitting the first form is rejected. |
| Remove equipment with past requests after all active loans and pending requests are resolved | Staff see it as **Archived**. It is hidden from the student catalog, and its records are still in history. |
| Mark equipment under maintenance, then submit an already-open request form or try to approve a pending request | Both actions are blocked. Existing loans can still be returned. |
| Try to issue more than five active units to the same student, including competing approvals for different equipment | The approval exceeding the configured limit is rejected. |
| Open a borrowing slip before and after return, then print it | The slip displays the appropriate dates and prints without navigation. Another student cannot access it. |
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

Students open **My profile** and staff also open **My profile** to find **Change password**. Enter the current password, a new password of at least 15 characters, and the new password again to confirm it. The form supports passwords up to 72 bytes.

After saving, use the new password for your next sign-in. If you receive an incorrect-current-password message, check the password you currently use to sign in.

To test, try an incorrect current password and mismatched new passwords first. Then save a valid new password, sign out, and check that the old password fails while the new password signs you in.

## Return reminders

The student dashboard shows **Returns due soon** when the signed-in student has an active loan due today or within the next three days. Reminders list the equipment, quantity, and due date, with the earliest due date first. They use the same database date as the overdue view.

To test, issue loans due today, tomorrow, in three days, and in four days. The first three appear in the reminder list. A returned loan drops out of the list, and each student sees only their own loans.

## CSV downloads

Staff can choose **Download CSV** on **Equipment** or **Export CSV** on **Requests**. The download uses the search and filters selected in that form. Choose **All statuses** on Requests to include the full borrowing history. A search with no matching records produces a file containing the column headings.

Inventory exports include quantities and the active/archive flag. Borrowing exports include student details, quantities, reasons, statuses, and dates. User-entered values that could be interpreted as spreadsheet formulas receive a leading apostrophe in the CSV.

To test, filter inventory to one equipment code and download it. Check that only that item appears. Then filter requests by a student ID and status and check that the download matches those filters.

## Pagination

Borrowing requests and active-loan lists show 15 records per page. Equipment catalogs and inventory show 10. Use **Previous** and **Next** below the list. Filters carry across pages, and applying a new filter starts at the first page. CSV downloads include all matching records.

To test, add at least 11 equipment entries or 16 requests in a test database. Check that the second page shows the remaining records. Then apply a filter and check that paging keeps it selected. Download a CSV and confirm that it includes matches from every page.

## Student history filters

Students can filter **My requests** by equipment and status, including **Overdue**. The equipment dropdown contains items from that student's own requests, including archived items. **Borrowed** includes all active loans, and **Overdue** selects active loans past their due date.

Use **Filter** to start at the first page or **Clear filters** to view all personal records. Paging and cancelling a pending request keep the current filters.

To test, choose equipment and a status together, go to the next page, and check that the filters stay selected. Cancel a pending request while filtering by **Pending** and confirm it drops out of that filtered list. Another student's requests stay private.

## Maintenance, borrowing limits, and slips

Staff can set **Equipment status** to **Under maintenance** when adding or editing equipment. The flag applies to the whole equipment type. It pauses new requests and approvals while existing loans can still be returned. Stock quantities keep tracking issued and returned units.

The default borrowing limit is **5 physical units per student** across all active loans, including overdue loans. Change MAX_BORROWED_UNITS in config/db.php to adjust it. Requests are checked on submission, and staff approval checks the limit again. Pending requests do not reserve the allowance. Existing loans above the limit can still be returned.

Students and staff can open **Borrowing slip** beside an issued or returned record. Use the browser's Print menu or Ctrl+P. Students can access slips for their own records. The slip includes student details, equipment, quantity, reason, issue date, due date, and return date when recorded.

For an existing database, run the following once in phpMyAdmin with labriqo selected. A new import already includes this field.

    ALTER TABLE equipment
    ADD COLUMN is_under_maintenance TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD CONSTRAINT equipment_maintenance_value CHECK (is_under_maintenance IN (0, 1));

Test maintenance by opening a request form, marking the equipment under maintenance as staff, and submitting the old form. Check that it is rejected. Test the borrowing limit with competing approvals for the same student across different equipment. Check a slip before and after return, then try its link from another student's account.

## Due-date extensions

On **My requests**, students can request a later due date for an active loan by entering a date and reason. The date must be later than the current due date and today or later. The current due date applies until approval. One extension per loan can await review at a time.

Staff open **Requests**, then **Review extensions**. Approving uses the date the student requested. Rejection requires a reason and keeps the current due date. Students can submit another extension after a decision. Each borrowing record stores its latest extension request and decision.

Extensions also work for overdue loans. Returning a loan closes any extension awaiting review. Decisions update the due date used by reminders, overdue lists, CSV exports, and printable slips. Stock and the quantity on loan are unaffected.

For an existing database, run this once in phpMyAdmin with labriqo selected. A new database import includes these columns.

    ALTER TABLE borrow_requests
    ADD COLUMN extension_due_date DATE DEFAULT NULL,
    ADD COLUMN extension_reason VARCHAR(1000) DEFAULT NULL,
    ADD COLUMN extension_status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT NULL,
    ADD COLUMN extension_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN extension_requested_at DATETIME DEFAULT NULL,
    ADD COLUMN extension_decided_at DATETIME DEFAULT NULL,
    ADD COLUMN extension_review_note VARCHAR(1000) DEFAULT NULL;

Test invalid dates, empty reasons, duplicate pending requests, another student's loan, approval, rejection, and a return before review. A staff form from an earlier extension must not decide a newer request.


## Local access and account protection

This XAMPP setup permits access from the same computer. The Labriqo Apache rules block repository and SQL files, turn off directory listings, and send PHP errors to the log. A network deployment needs its own HTTPS and access configuration.

New student registrations wait for staff approval. Staff must verify the student ID and email against the lab's own records before approving. Existing student accounts retain access when upgrading.

In each 15-minute window, sign-in allows 10 attempts per email or account and 50 per IP address. Registration allows five attempts per IP address. A student can have five pending or waitlisted requests and make up to 10 submission attempts per window. Changing the login email requires the current password. Sessions expire after 30 minutes idle or eight hours total. Equipment images use local files under assets/. Changing a password keeps the current browser signed in and ends previous sessions for that account. After upgrading, sign in again to create a session with the new checks.

For an existing database, select labriqo in phpMyAdmin and run the following once. A fresh import of database.sql already includes both changes.

    ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

    CREATE TABLE IF NOT EXISTS login_attempts (
        scope_key VARCHAR(70) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        window_start BIGINT UNSIGNED NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (scope_key, window_start),
        INDEX login_attempts_window (window_start)
    ) ENGINE=InnoDB;

## Inventory and lending workflows

| Feature | Where to use it |
| --- | --- |
| Registration rejection | Staff can reject an unverified signup under **Dashboard → Student account approvals**. This removes the pending registration and makes its student ID and email available again. |
| Equipment condition | Staff record condition when issuing and returning equipment. Notes appear on borrowing slips and in the requests CSV. |
| Stock adjustment history | Staff enter a reason when changing total stock and can view the date, staff member, and old and new totals under **Equipment → Stock history**. New stock is logged as initial inventory. Equipment with recorded history is archived when removed. |
| Collection deadlines | Staff choose **Reserve for pickup**, then **Confirm collection** under **Awaiting collection** when handing over the equipment. A reservation holds stock for 48 hours using the database clock. Expired reservations release stock the next time a signed-in user opens an app page. Reservations count toward the student's borrowing limit. |
| Waitlist | Students can choose **Join waitlist** when the quantity is unavailable or the equipment already has a queue. **My requests** shows their position. Staff process the oldest entry first under **Requests → Waitlist**. Waitlisted requests hold stock only after reservation or issue. |
| Individual asset tracking | In **Equipment → Assets**, enter one unique tag per unit once all units are in stock. Staff choose the exact tags at issue and verify them at return. Add or retire tagged units from the Assets page with a reason. Each asset links to its borrowing history and condition notes. Equipment without tags uses quantity-based checkout. |

### Upgrade an existing database

For an installation that already has the preceding features, select the labriqo database in phpMyAdmin and run this upgrade once. A fresh import of database.sql includes these changes.

```sql
ALTER TABLE equipment ADD COLUMN tracks_assets TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE borrow_requests
    ADD COLUMN issue_condition VARCHAR(1000) DEFAULT NULL,
    ADD COLUMN return_condition VARCHAR(1000) DEFAULT NULL,
    MODIFY COLUMN status ENUM('pending', 'borrowed', 'returned', 'rejected', 'cancelled', 'waitlisted', 'reserved', 'expired') NOT NULL DEFAULT 'pending',
    ADD COLUMN collection_deadline DATETIME DEFAULT NULL,
    ADD INDEX borrow_collection_deadline (status, collection_deadline),
    ADD INDEX borrow_equipment_queue (equipment_id, status, id);

CREATE TABLE IF NOT EXISTS equipment_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(80) NOT NULL UNIQUE,
    current_request_id INT UNSIGNED DEFAULT NULL,
    retired_at DATETIME DEFAULT NULL,
    CONSTRAINT assets_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT assets_current_loan_fk FOREIGN KEY (current_request_id) REFERENCES borrow_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_assets (
    request_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (request_id, asset_id),
    CONSTRAINT loan_assets_request_fk FOREIGN KEY (request_id) REFERENCES borrow_requests (id),
    CONSTRAINT loan_assets_asset_fk FOREIGN KEY (asset_id) REFERENCES equipment_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    previous_total INT NOT NULL,
    new_total INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_adjustment_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT stock_adjustment_staff FOREIGN KEY (staff_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


To test the workflow, reserve the last available units, add two students to the waitlist, and confirm the second student cannot receive the units ahead of the first. In a test database, set the reservation deadline to a past time and refresh a signed-in page. The reservation should expire and release stock once. Enable tags on an item, issue its units, and check that an incorrect tag is rejected at return.

## Partial returns, damaged or missing units, and staff activity

- **Partial returns.** In **Returns**, record any quantity up to the outstanding amount. For tagged equipment, enter the exact tags for that batch. The loan stays open for its outstanding units. The borrowing slip shows each recorded outcome.
- **Damaged or missing units.** Record each outcome as a separate batch. A damaged return goes to the repair hold and stays unavailable for lending. From **Equipment**, open the **held for inspection or repair** link to restock cleared units or discard them with a reason. A missing unit reduces total stock and retires its tag, if it has one. A loan closes when every issued unit is accounted for.
- **Staff activity.** Open **Dashboard → Staff activity** to see account decisions, request decisions, pickups, issues, extensions, return outcomes, and damage resolutions. The log starts recording after this update. Quantity adjustments remain available in **Equipment → Stock history**.

### Upgrade for return outcomes and staff activity

If your database already has individual asset tracking, run the following once in the labriqo database in phpMyAdmin. Existing completed loans keep their original close dates and condition notes. A fresh database import includes the new tables.

```sql
ALTER TABLE equipment
    ADD COLUMN damaged_quantity INT NOT NULL DEFAULT 0,
    ADD CONSTRAINT equipment_damaged_range CHECK (damaged_quantity >= 0 AND damaged_quantity <= total_quantity - available_quantity);

ALTER TABLE equipment_assets ADD COLUMN is_damaged TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE borrow_requests
    ADD COLUMN settled_quantity INT NOT NULL DEFAULT 0,
    ADD CONSTRAINT loan_settled_quantity CHECK (settled_quantity BETWEEN 0 AND quantity);

UPDATE borrow_requests SET settled_quantity = quantity WHERE status = 'returned';

CREATE TABLE IF NOT EXISTS loan_settlements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    outcome ENUM('returned', 'damaged', 'missing') NOT NULL,
    condition_note VARCHAR(1000) NOT NULL,
    asset_tags TEXT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES borrow_requests (id),
    FOREIGN KEY (staff_id) REFERENCES users (id),
    CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    action_name VARCHAR(80) NOT NULL,
    target_type VARCHAR(30) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    detail VARCHAR(500) DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Inspection at return and dashboard alerts

Staff choose **Good**, **Needs inspection**, or **Damaged** when recording a return. Notes are optional for these conditions. **Missing** remains available and requires a reason. Units needing inspection or repair stay out of available stock until staff clear the hold. Borrowing slips show the condition recorded for each batch.

The student dashboard links to overdue loans when a student has any. The staff dashboard shows up to five active equipment types with two or fewer units available, starting with the lowest stock.

For an existing database that already supports partial returns, run this once in the labriqo database in phpMyAdmin. A fresh database import already includes the inspection option.

    ALTER TABLE loan_settlements
        MODIFY COLUMN outcome ENUM('returned', 'damaged', 'missing', 'inspection') NOT NULL;
