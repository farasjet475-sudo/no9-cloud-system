# No9 Cloud System v5

Default logins:
- superadmin / admin123
- admin / admin123
- cashier / admin123

Install:
1. Extract to htdocs or your server root.
2. Create database `no9_cloud_system_v5`.
3. Import `database.sql`.
4. Update `config/config.php` if needed.
5. Ensure `uploads/products`, `uploads/payments`, `exports`, and `logs` are writable.

Included updates:
- Superadmin can create company + company admin username/password.
- Superadmin can manage company admins.
- Company admin manages company settings, backup/restore, products, branches, sub users.
- Sales only sell existing inventory products and reduce stock.
- Low stock alerts.
- Printable invoice, receipt, and quotation with company details.
- Messaging center for email / SMS log / WhatsApp link.
- Superadmin forgot password flow using temporary code via email.
- Everyone can edit profile.
- Superadmin reports for paid and unpaid companies.

Notes:
- Email uses PHP `mail()`; local XAMPP may not send until SMTP is configured.
- WhatsApp and SMS are logged/prepared; real gateway integration still depends on your provider/API.
- This package was syntax-checked, but you should still test your exact server setup before using it in a live business.
