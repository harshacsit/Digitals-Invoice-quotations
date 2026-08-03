# AdsDash Email Frontend Integration Test Cases

| # | Test Scenario | Steps | Expected Result | Pass/Fail |
| :--- | :--- | :--- | :--- | :--- |
| **1** | **Quotation Send Email Button Visibility** | Navigate to `quotations.html`. Inspect action column. | Envelope icon button is visible next to PDF buttons. | `PASS` |
| **2** | **Quotation Recipient Prefill** | Click envelope icon on quotation row with customer. | Modal opens with customer email and contact name pre-populated. | `PASS` |
| **3** | **Quotation Email Send Action** | Click "Send Quotation" in modal. | Submit button disables, spinner shows "Sending...", returns success toast. | `PASS` |
| **4** | **Invoice Send Email Action** | Navigate to `invoices.html`, click Send Email on invoice row. | Modal opens pre-populated with customer email; sends email with PDF attachment note. | `PASS` |
| **5** | **Payment Receipt Send Action** | Navigate to `payments.html`, click Send Receipt on payment row. | Modal opens; dispatches payment receipt email to customer. | `PASS` |
| **6** | **Campaign Update Send Action** | Navigate to `campaigns.html`, click Send Update on campaign row. | Modal opens; dispatches campaign schedule & progress update email. | `PASS` |
| **7** | **Staff System Notification Access** | Log in as staff user (`role = 'staff'`). Inspect `email-logs.html`. | "Send System Notification" button is hidden (`manager-only`). API blocks with 403. | `PASS` |
| **8** | **Manager System Notification Access** | Log in as manager user (`role = 'manager'`). Click "Send System Notification". | Modal opens; manager dispatches system notification successfully. | `PASS` |
| **9** | **Email History Log Table Loading** | Navigate to `email-logs.html`. | Table loads list of log entries with Recipient, Subject, Type, Status, and Timestamps. | `PASS` |
| **10** | **Email History Search Filter** | Type recipient email or subject in search box, press Enter. | Table filters results matching search term. | `PASS` |
| **11** | **Email Type Dropdown Filter** | Select `Quotation` or `Invoice` from Type dropdown. | Table displays only records matching selected email type. | `PASS` |
| **12** | **Status Dropdown Filter** | Select `Sent` or `Failed` from Status dropdown. | Table displays only records matching selected status. | `PASS` |
| **13** | **Server-Side Pagination** | Click Next / Page numbers on `email-logs.html`. | Loads next page of 20 log records. | `PASS` |
| **14** | **Email Detail Modal Inspection** | Click eye icon on log row. | Modal displays full log details (Recipient, Subject, Type, Attachment, Timestamps). | `PASS` |
| **15** | **Failed Email Error Masking** | View log detail for a failed email. | Error message is sanitized/safe. No database passwords or SMTP credentials exposed. | `PASS` |
| **16** | **Session Expiry Redirect** | Clear session cookies and trigger an email send. | Redirected to `login.html` (HTTP 401 response handled by `api.js`). | `PASS` |
| **17** | **Double-Click Submission Guard** | Rapidly double click "Send" button in email modal. | Button disables immediately on first click, preventing duplicate POST requests. | `PASS` |
| **18** | **Existing PDF Buttons Verification** | Click View PDF, Download PDF, Print PDF on Quotations/Invoices. | PDF documents generate and open in new tab without regression. | `PASS` |
| **19** | **Existing Quotation Workflow** | Create quotation, approve, convert to invoice. | End-to-end sales workflow completes without regression. | `PASS` |
| **20** | **Existing Invoice/Payment Workflows** | Record payment against invoice. | Invoice balance updates, status transitions to paid cleanly. | `PASS` |
