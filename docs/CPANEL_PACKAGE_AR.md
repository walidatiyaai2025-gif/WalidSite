# وليد سايت — دليل تثبيت باكيدج cPanel

هذه الحزمة تشمل تطبيق Laravel ومكتبات Composer الإنتاجية وملفات public_html والصورة الشخصية المعتمدة. لا تشمل أسرار الخادم أو بيانات قاعدة البيانات أو تطبيقات Flutter. تم إعدادها لرفعها من cPanel File Manager بعد نجاح اختبارات GitHub CI؛ ويظل فحص الاستضافة الفعلية مطلوبًا.

## قبل الرفع

1. تأكد أن cPanel يدعم PHP 8.3 أو أحدث وإضافات mbstring و PDO MySQL و openssl و xml و tokenizer و fileinfo و ctype. وفّر HTTPS على الدومين.
2. خذ Backup لأي موقع موجود، وأنشئ قاعدة بيانات MariaDB/MySQL جديدة ومستخدمًا لها من cPanel. احتفظ بالاسم وكلمة السر بعيدًا عن الملفات العامة.
3. ملف ZIP يحتوي على ثلاثة مجلدات رئيسية: application (خاص)، contracts (خاص)، public_html (عام). فك ضغطه من Home Directory للحساب (/home/USERNAME)، وليس من داخل public_html. سيتم دمج public_html مع الجذر العام الحالي؛ راجع أي ملفات متعارضة قبل الكتابة.
4. يجب أن يكون ترتيب الملفات كما يلي:
    /home/USERNAME/application/
    /home/USERNAME/contracts/owner-cv-baseline.json
    /home/USERNAME/public_html/index.php
    /home/USERNAME/public_html/.htaccess

إذا كنت تستعمل جذرًا آخر للدومين الإضافي، انقل محتويات public_html إليه واضبط مسار application في index.php ليتوافق مع موقع التطبيق خارج مجلد الويب العام. لا تنقل مجلد application أو .env أو contracts إلى public_html.

## إعداد التطبيق لأول مرة

1. من File Manager انسخ application/.env.example إلى application/.env ثم عدّل إعدادات هذا الملف الخاص:
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://YOUR-DOMAIN
    DB_CONNECTION=mysql
    DB_HOST=localhost
    DB_PORT=3306
    DB_DATABASE=YOUR_CPANEL_DATABASE
    DB_USERNAME=YOUR_CPANEL_DATABASE_USER
    DB_PASSWORD=YOUR_PRIVATE_DATABASE_PASSWORD
    CACHE_STORE=file
    SESSION_DRIVER=file
    QUEUE_CONNECTION=sync
    SITE_SETUP_ENABLED=true
    SITE_SETUP_TOKEN=YOUR_RANDOM_PRIVATE_TOKEN
    SITE_UPDATES_ENABLED=true
    SITE_AUTO_MIGRATE=false

2. أنشئ APP_KEY فريدًا قبل فتح الموقع من cPanel Terminal إن كان متاحًا:
    cd ~/application && php artisan key:generate
   أو ولّد مفتاحًا صحيحًا بطول 32 بايت عشوائيًا بواسطة أداة موثوقة وأضفه بصيغة base64 إلى .env. لا تستخدم مفتاح PHP Unit أو مثالًا ثابتًا.
3. أنشئ SITE_SETUP_TOKEN عشوائيًا بـ 32 حرفًا على الأقل (الأفضل 64 حرف hex من الأمر openssl rand -hex 32) واحفظه في .env فقط. لا تضف هذا الرمز إلى رابط المتصفح أو مستودع GitHub.
4. فعّل الكتابة لدى مستخدم PHP في application/storage و application/bootstrap/cache دون منح الجميع صلاحيات 777.
5. افتح https://YOUR-DOMAIN/setup عبر HTTPS. أدخل رمز التثبيت، ثم Next لفحص اتصال قاعدة البيانات وتشغيل migrations، ثم Next لتفعيل حساب المالك الحقيقي الموجود في مصدر CV بكلمة مرور جديدة قوية. التثبيت يمنع إعادة الإنشاء على نفس القاعدة بعد تفعيل المدير.
6. بعد نجاح التثبيت، اجعل SITE_SETUP_ENABLED=false، وامسح SITE_SETUP_TOKEN فورًا، ثم نفّذ php artisan config:clear إذا كان Config Cache مفعّلًا. افتح /admin/login.

## بعد التثبيت والتحديثات

- لعرض تحديثات قاعدة البيانات افتح /admin/updates بحساب المالك. يمكن تنفيذ migrations الموجودة في نسخة الكود المثبتة، دون أي رفع وتشغيل ملفات PHP مجهولة.
- للتحديثات التلقائية بعد رفع كود جديد موثوق فقط، اضبط SITE_AUTO_MIGRATE=true بعد تجهيز النسخ الاحتياطية وأضف Cron كل دقيقة:
    cd /home/USERNAME/application && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1
  مسار PHP يمكن أن يختلف حسب إعدادات استضافتك.
- النظام لا ينزّل تحديثات GitHub البرمجية إلى cPanel بنفسه. رفع باكيدج أحدث يتطلب نسخًا احتياطية ومسار نشر موثوقًا، ولا يجب استبدال application/.env أو بيانات المستخدمين في storage.
- لا يتم إنشاء قاعدة البيانات نفسها من واجهة /setup؛ أنشئها أولًا من cPanel. لا توجد بيانات اعتماد مثبتة داخل ZIP.

## تحقق سريع

- /up للاستجابة الصحية من إطار Laravel.
- /setup للتثبيت الأول فقط.
- /admin/login لتسجيل الدخول بعد الإعداد.
- /admin/updates لمراجعة migrations بعد دخول المالك.

ملاحظة: نجاح CI يعني صحة بناء الحزمة والاختبارات البرمجية على GitHub؛ لا يعتبر دليلًا على أن استضافتك اكتملت أو أن النشر النهائي T025 قد اجتاز القبول.
