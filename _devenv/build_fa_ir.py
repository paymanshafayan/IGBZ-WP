#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Phase 68 — build igbz-suite/languages/igbz-suite-fa_IR.{po,mo}.

Reads the POT template (made by `bash _devenv/makepot.sh`), applies the
translation dictionary below and writes the fa_IR catalog both as PO (the
editable source of truth, references preserved) and as a compiled little-endian
MO (what WordPress loads — msgfmt does not exist in this sandbox).

To extend a translation, edit TRANSLATIONS and re-run:
    python3 _devenv/build_fa_ir.py
Untranslated msgids are kept with an empty msgstr (English fallback) so the
catalog never lies about coverage.
"""
import re
import struct
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
POT = REPO / "igbz-suite/languages/igbz-suite.pot"
PO = REPO / "igbz-suite/languages/igbz-suite-fa_IR.po"
MO = REPO / "igbz-suite/languages/igbz-suite-fa_IR.mo"

TRANSLATIONS = {
    # ------------------------------------------------------------- RestApi (mobile app surface)
    "Authentication is required.": "احراز هویت لازم است.",
    "This endpoint is limited to store owners.": "این نقطهٔ اتصال فقط برای صاحبان فروشگاه است.",
    "Super admin only.": "فقط مدیر کل.",
    "Too many requests. Please slow down.": "درخواست‌ها بیش از حد زیاد است؛ کمی آرام‌تر.",
    "Too many failed attempts. Try again in a few minutes.": "تلاش‌های ناموفق بیش از حد شده است؛ چند دقیقهٔ دیگر دوباره امتحان کنید.",
    "The access token is not valid.": "توکن دسترسی معتبر نیست.",
    "The access token has expired. Use the refresh token.": "توکن دسترسی منقضی شده است؛ از توکن نوسازی استفاده کنید.",
    "The refresh token is not valid. Please sign in again.": "توکن نوسازی معتبر نیست؛ دوباره وارد شوید.",
    "A refresh token is required.": "توکن نوسازی لازم است.",
    "This session was revoked. Please sign in again.": "این نشست باطل شده است؛ دوباره وارد شوید.",
    "Session revoked. That device must sign in again.": "نشست باطل شد؛ آن دستگاه باید دوباره وارد شود.",
    "Session not found.": "نشست پیدا نشد.",
    "The account behind this token no longer exists.": "حسابِ پشت این توکن دیگر وجود ندارد.",
    "A username and password are required.": "نام کاربری و گذرواژه لازم است.",
    "The username or password is incorrect.": "نام کاربری یا گذرواژه نادرست است.",
    "Sign in with a username and password": "ورود با نام کاربری و گذرواژه",
    "Sign in with your phone number": "ورود با شمارهٔ تلفن همراه",
    "Username and password sign-in.": "ورود با نام کاربری و گذرواژه.",
    "Send a one-time code by SMS.": "ارسال کد یک‌بارمصرف با پیامک.",
    "Exchange the code for a token pair.": "تبدیل کد به جفت توکن.",
    "Rotate the token pair.": "نوسازی جفت توکن.",
    "A one-time code is sent by SMS.": "کد یک‌بارمصرف با پیامک ارسال می‌شود.",
    "A phone number is required.": "شمارهٔ تلفن لازم است.",
    "A valid phone number is required.": "شمارهٔ تلفن معتبر لازم است.",
    "Which sign-in methods this store offers.": "روش‌های ورودی که این فروشگاه ارائه می‌کند.",
    "Access token %1$s, refresh token %2$s. Refresh tokens rotate on use and a replayed one revokes the device.": "توکن دسترسی %1$s و توکن نوسازی %2$s. توکن نوسازی با هر استفادهچرخش می‌کند و بازپخش آن دستگاه را باطل می‌کند.",
    "Access tokens are short lived; call /auth/refresh with the refresh token to rotate them.": "توکن‌های دسترسی کوتاه‌عمرند؛ برای نوسازی، /auth/refresh را با توکن نوسازی صدا بزنید.",
    "List the caller's sessions.": "فهرست نشست‌های شما.",
    "Sign a specific device out.": "خروج یک دستگاه مشخص.",
    "Active sessions": "نشست‌های فعال",
    "%d unexpired session(s) across all devices.": "%d نشستِ منقضی‌نشده در همهٔ دستگاه‌ها.",
    "Every issued access token is recorded, so a lost phone can be signed out from here without resetting the password.": "هر توکن صادرشده ثبت می‌شود تا بتوان گوشی گمشده را از همین‌جا بیرون انداخت بدون تغییر گذرواژه.",
    "Pass a session id or all=1.": "شناسهٔ نشست یا all=1 را بفرستید.",
    "The signed-in customer.": "مشتری واردشده.",
    "Read and update the profile.": "خواندن و به‌روزرسانی نمایه.",
    "Order history.": "تاریخچهٔ سفارش‌ها.",
    "One order with its lines.": "یک سفارش با اقلامش.",
    "Order %1$s is now \"%2$s\".": "سفارش %1$s اکنون «%2$s» است.",
    "Unknown order status.": "وضعیت ناشناختهٔ سفارش.",
    "Customer not found.": "مشتری پیدا نشد.",
    "This customer has never ordered from your store.": "این مشتری تاکنون از فروشگاه شما خرید نکرده است.",
    "This order belongs to another store.": "این سفارش متعلق به فروشگاه دیگری است.",
    "Balance and ledger.": "موجودی و گردش حساب.",
    "Start a wallet top-up payment.": "شروع پرداخت شارژ کیف پول.",
    "Wallet top-up is disabled.": "شارژ کیف پول غیرفعال است.",
    "The minimum top-up is %s.": "کمینهٔ شارژ %s است.",
    "%s was added to your wallet.": "%s به کیف پول شما افزوده شد.",
    "Your wallet was topped up": "کیف پول شما شارژ شد",
    "Your wallet balance changed": "موجودی کیف پول شما تغییر کرد",
    "A refund landed in your wallet": "مبلغی به عنوان بازپرداخت به کیف پول شما بازگشت",
    "You earned cashback": "شما کش‌بک گرفتید",
    "You received a reward": "شما پاداش دریافت کردید",
    "You earned a commission": "شما پورسانت گرفتید",
    "BNPL contracts and schedule.": "قراردادهای اقساطی و جدول پرداخت.",
    "Instalment not found.": "قسط پیدا نشد.",
    "Pay an instalment from the wallet.": "پرداخت قسط از کیف پول.",
    "The wallet balance is not enough to cover this instalment.": "موجودی کیف پول برای پرداخت این قسط کافی نیست.",
    "You have paid the final instalment. Nothing is outstanding.": "آخرین قسط را پرداخت کردید؛ چیزی بدهی نمانده است.",
    "An instalment is due": "قسطی سررسید شده است",
    "Instalment of %1$s is due on %2$s.": "قسط %1$s در %2$s سررسید می‌شود.",
    "Instalment plan settled": "طرح اقساطی تسویه شد",
    "Enrolled courses and progress.": "دوره‌های ثبت‌نام‌شده و پیشرفت.",
    "Record lesson progress.": "ثبت پیشرفت درس.",
    "Enrolment not found.": "ثبت‌نام پیدا نشد.",
    "You are not enrolled on this course.": "شما در این دوره ثبت‌نام نکرده‌اید.",
    "Answers must be sent as an object of question id to answer.": "پاسخ‌ها باید به شکل شیءِ «شناسهٔ پرسش به پاسخ» فرستاده شوند.",
    "Not enough AI credits. Buy more or make a purchase to earn credits.": "اعتبار هوش مصنوعی کافی نیست؛ خرید کنید یا اعتبار بخرید.",
    "The AI studio is disabled.": "استودیوی هوش مصنوعی غیرفعال است.",
    "The AI studio is not available on this site.": "استودیوی هوش مصنوعی در این سایت موجود نیست.",
    "Referral link, clicks and commissions.": "پیوند معرف، کلیک‌ها و پورسانت‌ها.",
    "PSP payment history.": "تاریخچهٔ پرداخت‌های درگاه.",
    "Catalogue": "فروشگاه",
    "Product list with filters and paging.": "فهرست محصولات با صافی‌ها و صفحه‌بندی.",
    "One product with variations and gallery.": "یک محصول با تغییرات و گالری.",
    "Category tree for the current store.": "درخت دسته‌بندی فروشگاه فعلی.",
    "Category not found.": "دسته‌بندی پیدا نشد.",
    "This category does not belong to your store.": "این دسته‌بندی متعلق به فروشگاه شما نیست.",
    "A category name is required.": "نام دسته‌بندی لازم است.",
    "Type-ahead suggestions.": "پیشنهادهای حین تایپ.",
    "Product not found.": "محصول پیدا نشد.",
    "This product belongs to another store.": "این محصول متعلق به فروشگاه دیگری است.",
    "A tenant context is required to create a product.": "برای ساخت محصول، زمینهٔ مستأجر لازم است.",
    "Device": "دستگاه",
    "Devices": "دستگاه‌ها",
    "Devices and app": "دستگاه‌ها و برنامه",
    "A device id is required.": "شناسهٔ دستگاه لازم است.",
    "The device could not be registered.": "دستگاه ثبت نشد.",
    "Device registration removed.": "ثبت دستگاه حذف شد.",
    "Forget a device.": "فراموش‌کردن یک دستگاه.",
    "Forget": "فراموش کن",
    "Revoke this device.": "باطل‌کردن این دستگاه.",
    "Remove this device registration?": "ثبت این دستگاه حذف شود؟",
    "The caller's own devices.": "دستگاه‌های خودتان.",
    "%1$d device(s) registered, %2$d with a usable push token.": "%1$d دستگاه ثبت شده است؛ %2$d دستگاه توکن پوش قابل‌استفاده دارد.",
    "Registered devices": "دستگاه‌های ثبت‌شده",
    "Push token": "توکن پوش",
    "Register or refresh a push token.": "ثبت یا نوسازی توکن پوش.",
    "That device has no push token.": "آن دستگاه توکن پوش ندارد.",
    "Android only": "فقط اندروید",
    "iOS only": "فقط iOS",
    "iOS": "iOS",
    "Last seen (UTC)": "آخرین دیدار (UTC)",
    "Last used": "آخرین استفاده",
    "Issued": "صادرشده",
    "Refresh expires": "انقضای نوسازی",
    "Last send": "آخرین ارسال",
    "not configured": "پیکربندی نشده",
    "not signed in": "وارد نشده",
    "Send a notification": "ارسال اعلان",
    "Send notification": "ارسال اعلان",
    "Notification sent.": "اعلان ارسال شد.",
    "The notification could not be sent.": "اعلان ارسال نشد.",
    "Test notification": "اعلان آزمایشی",
    "Test push": "پوش آزمایشی",
    "Test notification delivered.": "اعلان آزمایشی تحویل شد.",
    "Send a test push to the caller.": "ارسال پوش آزمایشی به خودتان.",
    "If you can read this, push is working.": "اگر این را می‌خوانید، پوش کار می‌کند.",
    "No app has registered a device yet.": "هنوز برنامه‌ای دستگاهی ثبت نکرده است.",
    "No registered device matched this audience.": "دستگاه ثبت‌شده‌ای با این مخاطب پیدا نشد.",
    "FCM accepted none of the tokens.": "FCM هیچ‌یک از توکن‌ها را نپذیرفت.",
    "Firebase rejected the send.": "فایربیس ارسال را رد کرد.",
    "Delivered to %1$d of %2$d device(s). %3$d dead token(s) cleared, %4$d failure(s).": "تحویل به %1$d از %2$d دستگاه؛ %3$d توکنِ مرده پاک شد و %4$d خطا رخ داد.",
    "Broadcast (store owner only).": "ارسال همگانی (فقط صاحب فروشگاه).",
    "Everyone": "همه",
    "Only these user ids": "فقط این شناسه‌های کاربری",
    "Comma separated. Leave empty to reach every registered device.": "با ویرگول جدا کنید؛ خالی یعنی همهٔ دستگاه‌های ثبت‌شده.",
    "Deep link": "پیوند عمیق",
    "Optional, e.g. igbz://products/42 — the app opens this screen when the notification is tapped.": "اختیاری، مثل igbz://products/42 — برنامه با لمس اعلان همین صفحه را می‌گشاید.",
    "Push is switched off or Firebase is not configured. Fill in the service account JSON on the Mobile API settings tab.": "پوش خاموش است یا فایربیس پیکربندی نشده؛ JSON حساب سرویس را در زبانهٔ API موبایل وارد کنید.",
    "Push notifications are disabled in the settings.": "اعلان‌های پوش در تنظیمات غیرفعال‌اند.",
    "Push ready": "پوش آماده است",
    "Disabled. Devices still register, but nothing is delivered.": "غیرفعال. دستگاه‌ها ثبت می‌شوند اما چیزی تحویل نمی‌رود.",
    "Enabled but the service account JSON is missing or invalid. Paste it under Settings → Mobile API.": "فعال اما JSON حساب سرویس غایب یا نامعتبر است؛ آن را در تنظیمات ← API موبایل بچسبانید.",
    "The FCM service account is missing or incomplete.": "حساب سرویس FCM غایب یا ناقص است.",
    "Delivered through FCM HTTP v1. Tokens Google reports as dead are cleared automatically, so the counts below are real deliveries, not attempts.": "تحویل از طریق FCM HTTP v1. توکن‌هایی که گوگل مرده گزارش می‌کند خودکار پاک می‌شوند؛ پس شمارندهای زیر تحویل واقعی‌اند نه تلاش.",
    "FCM HTTP v1 for project %s. Tokens rejected by Google are cleared automatically.": "FCM HTTP v1 برای پروژهٔ %s. توکن‌های ردشده توسط گوگل خودکار پاک می‌شوند.",
    "Firebase project": "پروژهٔ فایربیس",
    "Firebase push": "پوش فایربیس",
    "JWT signing secret": "کلید امضای JWT",
    "Missing or too short. Generate one under Settings → Mobile API; tokens cannot be trusted until then.": "غایب یا خیلی کوتاه. در تنظیمات ← API موبایل یکی بسازید؛ تا آن زمان توکن‌ها قابل‌اعتماد نیستند.",
    "A secret of adequate length is stored encrypted.": "یک راز با طول کافی رمزنگاری‌شده ذخیره شده است.",
    "Token lifetimes": "عمر توکن‌ها",
    "Rate limiting": "محدودسازی نرخ",
    "%d requests per minute per client on authentication routes.": "%d درخواست در دقیقه برای هر کلاینت روی مسیرهای احراز هویت.",
    "Endpoints": "نقاط اتصال",
    "API base": "پایهٔ API",
    "API base URL": "نشانی پایهٔ API",
    "Authentication": "احراز هویت",
    "JWT authentication with refresh tokens, catalogue and account endpoints for the mobile app, device registration and Firebase push notifications.": "احراز هویت JWT با توکن‌های نوسازی، نقاط اتصال فروشگاه و حساب برای برنامهٔ موبایل، ثبت دستگاه و اعلان‌های پوش فایربیس.",
    "The back end for the mobile app: authenticated sessions, registered devices and push notifications.": "پشتوانهٔ برنامهٔ موبایل: نشست‌های احرازشده، دستگاه‌های ثبت‌شده و اعلان‌های پوش.",
    "Deep-link scheme, update gate, branding and feature flags.": "طرح پیوند عمیق، دروازهٔ به‌روزرسانی، برندینگ و پرچم‌های ویژگی.",
    "Deferred deep link: find the store for a phone number.": "پیوند عمیق تعویق‌شده: یافتن فروشگاه با شمارهٔ تلفن.",
    "No tenant is associated with this account.": "هیچ مستأجری به این حساب متصل نیست.",
    "No SMS provider is configured for this store.": "برای این فروشگاه سرویس پیامکی پیکربندی نشده است.",
    "Uses the store account credentials.": "از اعتبارنامهٔ حساب فروشگاه استفاده می‌کند.",
    "Legal agreement service is not available.": "سرویس توافق‌نامهٔ حقوقی در دسترس نیست.",
    "Invalid webhook token.": "توکن وب‌هوک نامعتبر است.",
    "Unknown payout provider.": "سرویس پرداخت ناشناخته.",
    "Verify a standalone domain first.": "نخست یک دامنهٔ مستقل را راستی‌آزمایی کنید.",
    "The VIP channel is not enabled.": "کانال VIP فعال نیست.",
    "Not a courier.": "پیک نیستید.",
    "This account is not an active courier.": "این حساب یک پیک فعال نیست.",
    "Shipment not found for this barcode.": "مرسوله‌ای با این بارکد پیدا نشد.",
    "Delivery could not be confirmed.": "تحویل تأیید نشد.",
    "COD failed.": "پرداخت در محل ناموفق بود.",
    "No file was uploaded.": "پرونده‌ای بارگذاری نشد.",
    "No image was uploaded.": "تصویری بارگذاری نشد.",
    "The upload failed.": "بارگذاری ناموفق بود.",
    "That file is not attached to this product.": "آن پرونده به این محصول پیوست نیست.",
    "A title and a body are required.": "عنوان و متن لازم است.",
    "A title and a message are required.": "عنوان و پیام لازم است.",
    "That post could not be published.": "آن پست منتشر نشد.",
    "Could not save the plan. The slug may already be in use.": "طرح ذخیره نشد؛ ممکن است نامک قبلاً استفاده شود.",
    "Changed from the mobile app by %s.": "تغییر از برنامهٔ موبایل توسط %s.",
    "Your order has been paid": "سفارش شما پرداخت شد",
    "Your order is complete": "سفارش شما کامل شد",
    "Your order is on hold": "سفارش شما در انتظار است",
    "Your order was cancelled": "سفارش شما لغو شد",
    "Your order was refunded": "سفارش شما بازپرداخت شد",
    "Payment successful": "پرداخت موفق",
    "Your payment of %s was confirmed.": "پرداخت %s تأیید شد.",
    "You have been enrolled": "برای شما ثبت‌نام انجام شد",
    "A new course is available in your library.": "دورهٔ تازه‌ای در کتابخانهٔ شما available است.",
    "The course \"%s\" is now available in your library.": "دورهٔ «%s» اکنون در کتابخانهٔ شماست.",

    # -------------------------------------------------------- front.js + storefront shortcodes
    "Sending…": "در حال ارسال…",
    "Send code": "ارسال کد",
    "Verifying…": "در حال بررسی…",
    "Copied!": "کپی شد!",
    "Sign in with your phone": "ورود با شمارهٔ تلفن",
    "Verification code": "کد تأیید",
    "Mobile number": "شمارهٔ همراه",
    "Sign in": "ورود",
    "We sent you a code.": "کدی برایتان فرستادیم.",
    "The account could not be created.": "حساب ساخته نشد.",
    "Unknown step.": "گام ناشناخته.",
    "No courses published yet.": "هنوز دوره‌ای منتشر نشده است.",
    "View course": "دیدن دوره",
    "Enrol now": "همین حالا ثبت‌نام",
    "Course assessment": "ارزیابی دوره",
    "Quiz: %s (enrol to take it)": "آزمون: %s (برای شرکت ثبت‌نام کنید)",
    "This quiz has no questions yet.": "این آزمون هنوز پرسشی ندارد.",
    "Submit answers": "ثبت پاسخ‌ها",
    "Pass mark %d%%": "نمرهٔ قبولی %d%%",
    "unlimited attempts": "تلاش نامحدود",
    "You have used every attempt on this quiz. Contact the instructor if you need another.": "همهٔ تلاش‌های این آزمون را مصرف کردید؛ اگر تلاش دیگر می‌خواهید با مدرس تماس بگیرید.",
    "Scored %d%% — not a pass this time.": "نمرهٔ %d%% — این بار قبول نشد.",
    "Passed with %d%%.": "با نمرهٔ %d%% قبول شدید.",
    "Best score so far: %d%%.": "بهترین نمره تاکنون: %d%%.",
    "Passed — best score %d%%.": "قبول شدید — بهترین نمرهٔ %d%%.",
    "%d%% complete": "%d%% کامل",
    "You need access to view the lessons.": "برای دیدن درس‌ها به دسترسی نیاز دارید.",
    "Download attachment": "دریافت پیوست",
    "%d min": "%d دقیقه",
    "%d-day free trial": "%d روز آزمایشی رایگان",
    "Choose plan": "انتخاب طرح",
    "No plans are available.": "طرحی در دسترس نیست.",
    "day": "روز",
    "week": "هفته",
    "month": "ماه",
    "year": "سال",
    "Today": "امروز",
    "Then": "سپس",
    "Subject to credit approval at checkout.": "مشروط به تأیید اعتبار در تسویه‌حساب.",
    "Pay in instalments": "پرداخت اقساطی",
    "View and verify": "دیدن و راستی‌آزمایی",
    "Your certificate": "گواهی شما",
    "You have completed this course. Anyone can confirm the certificate at the address below.": "این دوره را کامل کرده‌اید؛ هر کسی می‌تواند گواهی را در نشانی زیر تأیید کند.",
    "Certificate:": "گواهی:",
    "%1$s × %2$d": "%1$s × %2$d",
    "%d minute limit": "محدودیت %d دقیقه",

    # ------------------------------------------------------------- account endpoints (front)
    "My courses": "دوره‌های من",
    "Current balance": "موجودی فعلی",
    "Recent activity": "فعالیت اخیر",
    "No wallet activity yet.": "هنوز فعالیتی در کیف پول نیست.",
    "Top up your wallet": "شارژ کیف پول",
    "Online top-up is not available right now.": "شارژ برخط در حال حاضر ممکن نیست.",
    "Continue to payment": "ادامه به پرداخت",
    "Pay from wallet": "پرداخت از کیف پول",
    "Available instalment credit": "اعتبار اقساطی در دسترس",
    "You have no instalment plans.": "طرح اقساطی ندارید.",
    "Plan #%1$d — %2$s": "طرح شمارهٔ %1$d — %2$s",
    "Total %1$s · outstanding %2$s": "مجموع %1$s · مانده %2$s",
    "Limit %1$s · score %2$d": "سقف %1$s · امتیاز %2$d",
    "Instalment paid from your wallet.": "قسط از کیف پول پرداخت شد.",
    "The instalment could not be paid. Check your wallet balance.": "قسط پرداخت نشد؛ موجودی کیف پول را بررسی کنید.",
    "Join the affiliate programme and earn a commission on every order you refer.": "در برنامهٔ همکاری در فروش عضو شوید و از هر سفارشی که معرفی می‌کنید پورسانت بگیرید.",
    "Join now": "همین حالا عضو شوید",
    "Welcome to the affiliate programme.": "به برنامهٔ همکاری در فروش خوش آمدید.",
    "Your referral link": "پیوند معرف شما",
    "No commissions recorded yet.": "هنوز پورسانتی ثبت نشده است.",
    "Browse the catalogue": "دیدن فروشگاه",
    "You are not enrolled in any course yet.": "هنوز در دوره‌ای ثبت‌نام نکرده‌اید.",
    "Continue": "ادامه",
    "Wallet top-up": "شارژ کیف پول",
    "Order payment": "پرداخت سفارش",
    "Instalment payment": "پرداخت قسط",
    "Affiliate payout": "پرداخت پورسانت",
    "Subscription": "اشتراک",
    "Promotion": "تبلیغ",
    "Instagram reward": "پاداش اینستاگرام",
    "Refund": "بازپرداخت",
    "Cashback": "کش‌بک",
    "Affiliate commission": "پورسانت همکاری در فروش",
    "Security check failed.": "راستی‌آزمایی امنیتی ناموفق بود.",
    "The amount is outside the allowed range.": "مبلغ خارج از بازهٔ مجاز است.",

    # ------------------------------------------------------------------ VIP landing (front)
    "This post is yours. Open it in the app.": "این پست مال شماست؛ در برنامه بازش کنید.",
    "Your session expired. Please try again.": "نشست شما منقضی شد؛ دوباره تلاش کنید.",
    "You already have access to this post. Open it in the app.": "به این پست دسترسی دارید؛ در برنامه بازش کنید.",
    "This post is open to everyone. Open it in the app to watch it in full.": "این پست برای همه آزاد است؛ برای دیدن کامل آن در برنامه بازش کنید.",
    "This post is no longer available. Members see every new post the moment it goes live — join and you will not miss the next one.": "این پست دیگر در دسترس نیست. اعضا هر پست تازه را همان لحظهٔ انتشار می‌بینند — عضو شوید تا پست بعدی را از دست ندهید.",
    "%s likes": "%s پسند",
    "%s comments": "%s نظر",
    "A VIP post": "پست ویژه",
    "Open in the app": "بازکردن در برنامه",
    "Post preview": "پیش‌نمایش پست",
    "Two ways to unlock it": "دو راه گشودن آن",
    "How to unlock it": "چگونه گشوده شود",
    "Membership": "عضویت",
    "Every VIP post, this one included, for as long as your membership runs.": "همهٔ پست‌های ویژه، از جمله همین پست، تا زمانی که عضویتتان برقرار است.",
    "Become a member": "عضو شوید",
    "Just this post": "فقط همین پست",
    "Buy this post": "خرید همین پست",
    "A one-off payment, no membership. It unlocks this post for as long as the post is online — save it in the app to keep it after that.": "یک پرداخت یک‌باره، بدون عضویت. این پست تا وقتی برخط است برایتان باز می‌شود — برای نگه‌داشتنش بعد از آن، در برنامه ذخیره‌اش کنید.",
    "Already a member? Sign in.": "عضو هستید؟ وارد شوید.",
    "Add a note (optional)": "یادداشت (اختیاری)",
    "Send support": "حمایت بفرست",
    "Another amount": "مبلغ دیگر",
    "Support this post": "حمایت از این پست",
    "Liked it? Buy us a coffee. No account needed.": "خوشتان آمد؟ برایمان یک قهوه بخرید. حساب لازم نیست.",
    "Install the app, sign in with the same phone number, and everything you buy here is waiting for you inside it.": "برنامه را نصب کنید و با همان شمارهٔ تلفن وارد شوید؛ هر چه اینجا خریدید در انتظارتان است.",
    "This is a private post from %s. The version on Instagram is only a preview — the full one lives in our app, where members watch it without ads, comment, and message us directly.": "این پست خصوصی از %s است. نسخهٔ اینستاگرام فقط پیش‌نمایش است — نسخهٔ کامل در برنامهٔ ماست؛ جایی که اعضا بدون تبلیغ تماشا می‌کنند، نظر می‌گذارند و مستقیم پیام می‌دهند.",
    "What is this?": "این چیست؟",
    "This post is for members. Open the app to see how to join.": "این پست ویژهٔ اعضاست؛ برنامه را باز کنید تا راه عضویت را ببینید.",
    "Get the app": "دریافت برنامه",
    "iPhone": "آیفون",
    "Android": "اندروید",
    "Direct download (APK)": "دریافت مستقیم (APK)",
    "Already installed? This post opens at %s": "نصب کرده‌اید؟ این پست در %s باز می‌شود",
    "Your membership is active. Open the app to start watching.": "عضویت شما فعال است؛ برنامه را باز کنید و تماشا را آغاز کنید.",
    "Expiring now": "در حال پایان",
    "Available for another %s": "تا %s دیگر در دسترس",
    "Toman": "تومان",
    "Want to keep it? After buying, tap the save icon on the post in the app and your own copy stays in the app.": "می‌خواهید نگهش دارید؟ بعد از خرید، در برنامه روی نشان ذخیره بزنید تا نسخهٔ خودتان در برنامه بماند.",
    "Want to keep it? Tap the save icon on the post in the app and your own copy stays in the app.": "می‌خواهید نگهش دارید؟ در برنامه روی نشان ذخیره بزنید تا نسخهٔ خودتان در برنامه بماند.",
    "This post is available until %s and is then removed from the server.": "این پست تا %s در دسترس است و بعد از آن از سرور حذف می‌شود.",
    "The link may be mistyped, or the post has been taken down. Members always see the latest posts in the app.": "شاید پیوند اشتباه باشد یا پست برداشته شده باشد. اعضا همیشه تازه‌ترین پست‌ها را در برنامه می‌بینند.",
    "This post is not here": "این پست اینجا نیست",
    "Go to the site": "رفتن به سایت",

    # --------------------------------------------------------------------- hub front + misc
    "Active stores": "فروشگاه‌های فعال",
    "No stores to show yet.": "هنوز فروشگاهی برای نمایش نیست.",
    "No products to show yet.": "هنوز محصولی برای نمایش نیست.",

    # ------------------------------------------------------------------- phase 68 settings
    "Iranian Toman": "تومان ایرانی",
    "Register the Iranian Toman (IRT) currency": "ثبت واحد پول تومان ایرانی (IRT)",
    "Checkout defaults to the store country for guests and new customers": "پیش‌فرضِ کشور تسویه‌حساب برای مهمان و مشتری تازه، کشور فروشگاه",
    "Persian digits on the storefront": "ارقام فارسی در ویترین فروشگاه",
}


def unescape(s: str) -> str:
    return s.replace("\\n", "\n").replace("\\t", "\t").replace('\\"', '"').replace("\\\\", "\\")


def escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\t", "\\t")


def parse_pot(path: Path):
    """Yield (references, msgid, msgid_plural_or_None) for every catalog entry."""
    text = path.read_text(encoding="utf-8")
    entry_re = re.compile(
        r"((?:#: [^\n]+\n)+)msgid ((?:\"(?:[^\"\\]|\\.)*\"\n)+)"
        r"(?:msgid_plural ((?:\"(?:[^\"\\]|\\.)*\"\n)+))?"
        r"((?:msgstr(?:\[\d+\])? (?:\"(?:[^\"\\]|\\.)*\"\n)+)+)",
    )
    for m in entry_re.finditer(text):
        refs = m.group(1)
        def join(raw):
            if not raw:
                return None
            parts = re.findall(r'"((?:[^"\\]|\\.)*)"', raw)
            return unescape("".join(parts))
        yield refs.strip(), join(m.group(2)), join(m.group(3))


def wrap_po(s: str) -> list:
    """PO string as one or more quoted lines; newline as \\n escape."""
    body = escape(s)
    if len(body) <= 77:
        return ['"%s"' % body]
    words, lines, cur = body.split(" "), [], ""
    for w in words:
        cand = (cur + " " + w).strip()
        if len(cand) > 77 and cur:
            lines.append('"%s"' % cur)
            cur = w
        else:
            cur = cand
    if cur:
        lines.append('"%s"' % cur)
    return lines


HEADER = (
    'msgid ""\n'
    'msgstr ""\n'
    '"Project-Id-Version: IGBZ Suite 1.0.0\\n"\n'
    '"MIME-Version: 1.0\\n"\n'
    '"Content-Type: text/plain; charset=UTF-8\\n"\n'
    '"Content-Transfer-Encoding: 8bit\\n"\n'
    '"PO-Revision-Date: 2026-08-30 00:00+0000\\n"\n'
    '"Last-Translator: IGBZ\\n"\n'
    '"Language-Team: Persian\\n"\n'
    '"Language: fa_IR\\n"\n'
    '"Plural-Forms: nplurals=2; plural=(n > 1);\\n"\n'
    '"X-Domain: igbz-suite\\n"\n'
)


def main() -> int:
    if not POT.exists():
        print("POT missing — run: bash _devenv/makepot.sh", file=sys.stderr)
        return 1

    entries = list(parse_pot(POT))
    translated = {k: v for k, v in TRANSLATIONS.items() if k}

    # ---- sanity: every dictionary key must exist in the template (a stale
    # translation is a lie). Report and drop the orphans loudly.
    msgids = {mid for _, mid, _ in entries if mid}
    orphans = sorted(set(translated) - msgids)
    if orphans:
        for o in orphans:
            print("orphan translation (msgid not in POT): %r" % o[:90], file=sys.stderr)

    po_parts = [
        "# Persian translation for the IGBZ Suite.\n"
        "# Generated by _devenv/build_fa_ir.py — edit the dictionary there, not this file.\n"
        "# Untranslated entries stay empty on purpose (English fallback).\n",
        HEADER,
    ]
    mo: dict = {}
    header_meta = (
        "Project-Id-Version: IGBZ Suite 1.0.0\n"
        "MIME-Version: 1.0\n"
        "Content-Type: text/plain; charset=UTF-8\n"
        "Content-Transfer-Encoding: 8bit\n"
        "PO-Revision-Date: 2026-08-30 00:00+0000\n"
        "Language-Team: Persian\n"
        "Language: fa_IR\n"
        "Plural-Forms: nplurals=2; plural=(n > 1);\n"
        "X-Domain: igbz-suite\n"
    )
    mo[""] = header_meta

    used = 0
    for refs, mid, plural in entries:
        if not mid:
            continue
        tr = translated.get(mid)
        po_parts.append("\n" + refs + "\n")
        po_parts.append("".join(wrap_po(mid)).join(["msgid ", "\n"]))
        if plural is not None:
            po_parts.append("".join(wrap_po(plural)).join(["msgid_plural ", "\n"]))
            if tr:
                po_parts.append('msgstr[0] "%s"\n' % escape(tr))
                po_parts.append('msgstr[1] "%s"\n' % escape(tr))
                mo[mid + "\x00" + plural] = tr + "\x00" + tr
            else:
                po_parts.append('msgstr[0] ""\nmsgstr[1] ""\n')
        else:
            if tr:
                po_parts.append("".join(wrap_po(tr)).join(["msgstr ", "\n"]))
                mo[mid] = tr
                used += 1
            else:
                po_parts.append('msgstr ""\n')

    PO.write_text("".join(po_parts), encoding="utf-8")

    # ---- compile the MO (little-endian GNU format, hash table omitted: size 0)
    keys = sorted(mo.keys())
    ids = b""
    strs = b""
    offsets = []
    for k in keys:
        kid = k.encode("utf-8")
        v = mo[k].encode("utf-8")
        offsets.append((len(kid), len(ids), len(v), len(strs)))
        ids += kid + b"\x00"
        strs += v + b"\x00"
    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets, voffsets = [], []
    for klen, koff, vlen, voff in offsets:  # (length, offset) pairs, in keys order
        koffsets += [klen, koff + keystart]
        voffsets += [vlen, voff + valuestart]
    output = struct.pack("Iiiiiii", 0x950412DE, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    output += array_pack(koffsets) + array_pack(voffsets)
    output += ids + strs
    MO.write_bytes(output)

    plural_used = sum(1 for k in keys if "\x00" in k)
    print(
        "fa_IR catalog: %d entries, %d translated (%d plural), orphans dropped: %d"
        % (len(entries), used, plural_used, len(orphans))
    )
    return 0


def array_pack(values):
    return b"".join(struct.pack("i", v) for v in values)


if __name__ == "__main__":
    raise SystemExit(main())
