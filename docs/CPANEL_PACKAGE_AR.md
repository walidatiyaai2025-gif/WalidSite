# وليد سايت — دليل تثبيت باكيدج cPanel

هذه الحزمة تشمل تطبيق Laravel ومكتبات Composer الإنتاجية وملفات public_html والصورة الشخصية المعتمدة. لا تشمل أسرار الخادم أو بيانات قاعدة البيانات أو تطبيقات Flutter. تم إعدادها لرفعها من cPanel File Manager بعد نجاح اختبارات GitHub CI؛ ويظل فحص الاستضافة الفعلية مطلوبًا.

## تنزيل الحزمة والتحقق من سلامتها

1. افتح صفحة **Actions → PR CI** في GitHub واختر تشغيل **main** الناجح الذي يحتوي على خطوة `build-cpanel-package` بعد نجاح `required-ci-gate` على نفس SHA. نزّل Artifact باسم `WalidSite-cPanel-installer` وفك ضغط ملف الـArtifact على جهازك؛ ستجد `WalidSite-cPanel.zip` و`WalidSite-cPanel.zip.sha256`.
2. تأكد أن الـSHA الظاهر في `PACKAGE-MANIFEST.json` داخل الحزمة يساوي SHA تشغيل GitHub الناجح الذي قمت بتنزيله؛ وجود SHA في ملف بمفرده لا يثبت الهوية أو التوقيع الرقمي.
3. من الطرفية، في نفس مجلد الملفين، نفّذ `sha256sum -c WalidSite-cPanel.zip.sha256` (على macOS: `shasum -a 256 -c WalidSite-cPanel.zip.sha256`)، ثم `unzip -t WalidSite-cPanel.zip` **قبل** استخراج ملفات الاستضافة. عند أي اختلاف أو فقدان لملف checksum لا تستخدم الملف.
4. سكربت البناء يراجع كل ملف داخل ZIP مقابل بصمة SHA256 وحجمه في الـManifest؛ هذه خطوة سلامة للمحتوى، وليست إثبات نشر أو توقيع رسمي. لا تضع ملفات المفاتيح أو `.env` داخل public_html.

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

## التثبيت الجديد — من المتصفح فقط دون Terminal أو تحرير .env

1. جهّز **قاعدة بيانات فارغة** واسم مستخدم وكلمة مرور لها من **cPanel → MySQL Databases**. هذه خطوة الاستضافة الوحيدة قبل المعالج؛ الموقع لا يستطيع إنشاء قاعدة MySQL من دون صلاحيات لوحة الاستضافة.
2. فك الباكيدج في **/home/USERNAME**، بحيث تكون application وcontracts خارج public_html. شغّل الدومين باستخدام HTTPS. Laravel يولّد مفتاح APP_KEY ورمز التثبيت تلقائيًا بمجرد التشغيل لأول مرة؛ لا تحتاج Terminal أو كتابة .env.
3. افتح **https://YOUR-DOMAIN/setup**. داخل cPanel File Manager افتح الملف الخاص **/home/USERNAME/application/storage/app/site-first-run.json** باستخدام **View**، وانسخ قيمة **token** فقط، ثم الصقها في أول خطوة. **لا تنشر هذا الملف أو ترسله إلى أحد**: يحتوي على بيانات تأسيس سرية. وجود الرمز خارج public_html ضروري لمنع زائر غريب من الاستيلاء على الموقع قبل إنشاء حساب المالك.
4. اضغط **Next**: أدخل Database Host (عادة localhost)، Port (عادة 3306)، اسم قاعدة البيانات، اسم المستخدم وكلمة المرور. المعالج **يفحص الاتصال ويتأكد أن قاعدة البيانات فارغة** قبل الحفظ. لا يعرض كلمة المرور أو يكتبها في سجل HTTP. يُنشئ التطبيق ملف application/.env تلقائيًا بصلاحيات خاصة، ويُفعّل APP_DEBUG=false.
5. اضغط **Next** لتنفيذ Laravel Migrations من الواجهة، ثم **Next** لتعيين كلمة مرور حساب المالك من CV الحقيقي المعتمد. يمنع النظام إعادة التثبيت على قاعدة مفعلة؛ ويغلق الرمز والمعالج بعد النجاح.
6. افتح **/admin/login** وسجّل الدخول. بعد ذلك التحديثات: **/admin/updates**. لا تعطل حماية التخزين أو تنقل application تحت public_html.

إذا لم يظهر الملف الخاص site-first-run.json، راجع صلاحيات كتابة application/storage/app وتأكد أن PHP 8.3+ وHTTPS يعملان، من دون 777. إذا كانت الاستضافة تمنع كتابة application/.env، تظهر رسالة واضحة دون تسريب معلومات الاتصال. لا تُدخل بيانات Production لقاعدة تحتوي بيانات موجودة؛ استخدم قاعدة جديدة وفارغة.

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
