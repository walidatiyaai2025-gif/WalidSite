# T009 public contact delivery (fail-closed)

The GET /contact view and POST /contact endpoint remain **disabled by default**. An operator must configure `MAIL_CONTACT_RECIPIENT`, `MAIL_FROM_ADDRESS`, and a functioning `MAIL_MAILER` (smtp, sendmail, ses, ses-v2, postmark, resend) through the **server environment**; never commit credentials. `log`, `array`, `failover`, and `roundrobin` transport modes do not qualify as verified delivery. Even after configuration, actual receipt requires a staged send/receive smoke test.

Forms use web CSRF middleware, 4 requests/minute, strict field validation, honeypot interception and 503 errors for unavailable delivery. Successful redirect means only that Laravel transport accepted the message, not confirmed inbox receipt.

Project and case-study views reuse the public evidence API serializer and filter out unsafe links. Printable resume uses the browser's Print/PDF function with only reviewed owner data, not a separate signed downloadable PDF.
