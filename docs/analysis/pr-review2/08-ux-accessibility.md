# 08 — Kullanıcı Deneyimi, CSS ve Erişilebilirlik

> PR #3 sistematik review · düşmanca doğrulamalı · 27 bulgu

## Özet

Temel semantik altyapı, bu kategorideki çoğu WordPress ödeme eklentisinin üzerinde: gerçek radio input'lar + role="radiogroup", th scope="col", screen-reader caption'lar, satır-özgü aria-label'lar, aria-hidden SVG'ler, modal odak tuzağı ve odak geri verme, aria-invalid/aria-describedby ile alan hataları, tasarlanmış boş durumlar. Ancak CSS katmanı PHP'nin ürettiği durumların önemli bir kısmını hiç kapsamıyor: 11 defter durumundan yalnızca 6'sının rozet stili var (en kritik needs_reconciliation dahil 5'i görünmez), .nicepay-notice taban sınıfının hiç arka planı yok (test modu uyarısı düz metin olarak kayboluyor) ve tüm kısakod hata çıktılarının kullandığı .nicepay-error sınıfı CSS'te hiç tanımlı değil. Yönetici işlem tablosu wp-list-table sözleşmesine column-primary/data-colname olmadan giriyor, bu da 782px altında tabloyu kullanılamaz hale getiriyor. Kontrast tarafında --nicepay-text-muted (#9ca3af, beyaz üzerinde 2.54:1) yalnızca placeholder'da değil gerçek bilgilendirme metinlerinde de kullanılıyor; hata bildirimi metni 4.41:1'de kalıyor. RTL desteği, prefers-color-scheme ve forced-colors tamamen yok; prefers-reduced-motion yalnızca ön yüzde ve eksik kapsanmış. Ayrıca standalone formda alıcı alanları form etiketinin dışında duruyor (Enter ile gönderim ve required çalışmıyor) ve standalone akışında hiçbir erişilebilir yükleniyor geri bildirimi yok.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 19 |
| 🔵 Düşük | 6 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 5 |
| Bağımsız doğrulama kararı alan bulgu | 7 / 27 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 14 |

## Güçlü yönler

- Ödeme yöntemi seçici gerçek input type=radio + label sarmalı ile kurulmuş, role=radiogroup + aria-labelledby taşıyor; ok tuşu navigasyonu ve AT semantiği ücretsiz geliyor (templates/payment-form.php:41-51, templates/standalone-payment-form.php:107-118).
- Tüm tablolarda th scope=col ve caption class=screen-reader-text kullanılmış (admin/class-nicepay-transactions.php:718, 750-761); filtre kontrollerinin hepsinin screen-reader-text label'ı var (660-685).
- İkon-yalnız butonlar satır bağlamı içeren aria-label taşıyor (Copy TID %s, Cancel transaction %s) ve SVG'leri aria-hidden=true focusable=false (admin/class-nicepay-transactions.php:842-845, 924-930).
- nicepay_get_method_icon() üretilen tüm ikonlar aria-hidden=true span ile sarılıyor (includes/nicepay-icons.php:74).
- Standalone alan doğrulaması aria-invalid + aria-describedby + role=alert ile doğru bağlanmış ve ilk hatalı alana odak veriyor (assets/js/nicepay.js:221-236).
- Her iki modal da odağı açılıştan önce saklayıp kapanışta geri veriyor, Escape'i destekliyor ve Tab tuzağı uyguluyor (assets/js/nicepay.js:393-441, assets/js/nicepay-admin.js:99-238).
- Boş durumlar (ikon + başlık + açıklama) hem işlem tablosu hem kısakod listesi için tasarlanmış (admin/class-nicepay-transactions.php:765-776, admin/class-nicepay-admin.php:753-758).
- Ayarlar sayfasındaki hazırlık paneli her sekmede 7 maddelik somut, eyleme dönük kontrol listesi gösteriyor; onay/çarpı işaretleri aria-hidden ve metinle yedeklenmiş (admin/class-nicepay-admin.php:425-463).
- Kimlik bilgisi alanları type=password + autocomplete=new-password + boş bırakınca korunma + ayrı temizleme onay kutusu ile doğru tasarlanmış (admin/class-nicepay-admin.php:658-691).
- Saklama politikası formu fieldset + screen-reader legend + açık onay kutusu ve dört maddelik yasal uyarı ile ciddiyetine uygun kurgulanmış (admin/class-nicepay-admin.php:522-600).
- nicepayParams.i18n'de nicepay.js'in translated() içinde kullandığı 12 anahtarın tamamı gerçekten sağlanmış — sessiz İngilizce'ye düşüş yok (nicepay-payment-gateway.php:509-523).
- Butonlar gerçek button type=button; hiçbir yerde div onclick yok. Sunucu tarafı çıktı tutarlı biçimde escape edilmiş.

## Bulgular

### UX-004 — Standalone formda görünür alıcı alanları form elemanının dışında — Enter ile gönderim ve native required doğrulaması tamamen ölü

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | forms |
| **Konum** | [templates/standalone-payment-form.php:123](../../../templates/standalone-payment-form.php#L123) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Görünür alanlar `<div class="nicepay-buyer-fields">` içinde 123-153 satırlarında; `<form id="..." class="nicepay-standalone-form">` ise ancak 156. satırda açılıp 187'de kapanıyor. Yani -name / -email / -tel input'ları form'un DIŞINDA ve hiçbirinde form="<form_id>" niteliği yok. Bu alanlara 131, 140, 149. satırlarda required verilmiş; 179-185'teki gönder butonu ise type="button". assets/js/nicepay.js:367-374 değerleri submit anında gizli input'lara kopyalıyor, yani işlev yalnızca JS ile ayakta.
```

**Başarısızlık senaryosu**

Mobil klavye kullanan bir alıcı ad, e-posta ve telefonu dolduruyor; telefon alanında klavyedeki Git/Enter tuşuna basıyor. Hiçbir şey olmuyor. Alıcı formun takıldığını düşünüp sayfayı terk ediyor — tam ödeme adımının son saniyesinde dönüşüm kaybı.

**Etki**

(1) Klavye kullanıcısı e-posta alanında Enter'a bastığında hiçbir şey olmuyor — hiçbir form ile ilişkilendirilmedikleri için implicit submission tetiklenemiyor. (2) required nitelikleri hiçbir zaman çalışmıyor. (3) Tarayıcı otomatik doldurma alanları tek form bağlamı olarak gruplayamıyor. (4) Ekran okuyucu form modunda bu alanları formun parçası saymıyor.

**Öneri**

Alıcı alanlarını form içine taşı (gizli input bloğunun üstüne) ve fieldset + legend ile grupla. Taşımak mümkün değilse her görünür input'a form="<?php echo esc_attr( $form_id ); ?>" ekle. Her iki durumda da butonu type="submit" yapıp form üzerinde submit olayını dinle (preventDefault + startStandalone), böylece Enter doğal çalışsın. Doğrulamayı JS'te tutmaya devam edeceksen novalidate ekle ve required yerine aria-required="true" kullan.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Standalone formda görünür alıcı alanları `<form>` elemanının dışında (templates/standalone-payment-form.php:124-152 vs form 156-187) ve `form=` niteliği taşımıyor; buton `type="button"` (179) ve JS'te hiçbir submit/Enter dinleyicisi yok (assets/js/nicepay.js: submit listener yok; tek keydown 426 modal Escape/Tab için). Sonuç: (a) alanlarda Enter/Go'ya basmak hiçbir şey yapmaz — implicit submission mümkün değil; (b) 131/140/149'daki `required` nitelikleri hiçbir zaman değerlendirilmez (JS doğrulaması nicepay.js:195-234 bunu telafi ediyor, dolayısıyla kullanıcı doğrulamasız kalmıyor); (c) autofill gruplaması zayıflar (ama autocomplete nitelikleri sayesinde doldurma yine çalışır). Ekran okuyucu forms-mode iddiası GEÇERSİZ — label/for bağları doğru ve forms-mode `<form>` atası gerektirmez. Ayrıca önerinin "alanları form içine taşı" kısmı tek başına yetersiz ve riskli: 3 alan varken submit butonu olmadığı için Enter yine çalışmaz, tek alan eksik senaryosunda ise Enter formu boş hidden değerlerle `action` (satır 160, return URL) adresine gerçekten POST eder ve bozuk bir dönüş isteği üretir. Doğru düzeltme taşıma + `novalidate` + `type="submit"` + `submit` dinleyicisinde `preventDefault()` + `startStandalone()` kombinasyonudur (veya alanlar yerinde kalıp Enter için keydown delegasyonu eklenmesi), `required` yerine `aria-required="true"`.
- Gerekçe: STRUCTURAL ÇEKİRDEK DOĞRU, KANITLANDI. Görünür alıcı input'ları gerçekten `<form>`'un dışında ve hiçbirinde `form=` niteliği yok; buton `type="button"`; JS'te hiçbir `submit` veya Enter/keydown dinleyicisi bu formlara bağlanmıyor (tek keydown dinleyicisi modal Escape/Tab tuzağı, nicepay.js:405-427). Dolayısıyla (1) Enter ile gönderim ve (2) native `required` doğrulaması gerçekten ölü. Buraya kadar iddia doğru.

ANCAK ÜÇ DÜZELTME:

A) İSTİSMAR/ETKİ ZİNCİRİ ABARTILMIŞ. İddianın (4) numaralı etkisi yanlış: ekran okuyucular (NVDA/JAWS) forms/focus modunu odaklanabilir form kontrolüne göre tetikler, `<form>` atası şartı değildir; input'ların `<label for>` bağlantısı doğru (127-131, 136-140, 145-149), yani ekran okuyucu deneyimi bozulmuyor. (3) numaralı etki de zayıf: `autocomplete="name|email|tel"` mevcut ve tarayıcılar formsuz input'ları da doldurur; sadece gruplama sezgisi zayıflar. Gerçek kalan etki tek başına Enter/implicit submission + ölü `required`.

B) FAILURE SCENARIO ÜRETİLEBİLİR AMA ETKİSİ DAHA KÜÇÜK. Somut adım: shortcode `[nicepay_payment id="X"]` (buyer_* boş) -> `$show_buyer_fields` true -> ziyaretçi (giriş gerekmez, herhangi bir anonim kullanıcı) telefon alanında Enter/Go'ya basar -> hiçbir handler yok, hiçbir form owner yok -> hiçbir şey olmaz. Bu üretilebilir. Fakat "alıcı formun takıldığını sanıp terk eder" abartı: görünür bir `nicepay-pay-button` (179-185) hemen altta duruyor ve tıklanınca çalışıyor; ayrıca form owner'ı olmayan input'ta mobil klavye genelde "Go/Git" değil "return/Done" gösterir, yani senaryonun tetikleyicisi bile daha nadir. Kayıp = tamamen bloke değil, sürtünme. Bu yüzden severity high değil MEDIUM.

C) ÖNERİNİN İLK YARISI TEK BAŞINA YANLIŞ/ TEHLİKELİ. "Alanları form içine taşı" tek başına Enter'ı ÇALIŞTIRMAZ: form içinde submit butonu olmadığı için (buton type="button") ve implicit submission'ı bloke eden birden fazla text alanı bulunduğu için HTML spesifikasyonu gereği implicit submission yine tetiklenmez. DAHA KÖTÜSÜ: sadece tek bir alıcı alanı eksikse (ör. buyer_name ve buyer_email preset, sadece tel eksik), o tek alan implicit submission'ı bloke etmez ve Enter formu doğrudan `action="<?php nicepay_get_standalone_return_url() ?>"` (satır 160) adresine BOŞ hidden alanlarla (Amt/MID/SignData/Moid hepsi value="") POST eder — yani "düzeltme" mevcut durumdan daha kötü, bozuk bir dönüş sayfası isteği üretir. Doğru düzeltme: alanları form içine taşı + form'a `novalidate` + butonu `type="submit"` + `form.addEventListener('submit', e => { e.preventDefault(); startStandalone(id); })` üçünü BİRLİKTE yap; ya da alanları yerinde bırakıp sadece `keydown` (Enter) delegasyonu ekle. Ayrıca `required` JS doğrulaması korunacaksa `aria-required="true"`e çevrilmeli — bu kısım doğru.

Not: mevcut JS doğrulaması (nicepay.js:195-234) aslında native'den daha iyi — byte-uzunluğu, e-posta/telefon regex, `aria-invalid`, `role="alert"` hata düğümü, ilk hatalı alana odak. Yani "doğrulama yok" değil, "native doğrulama ölü" doğru ifade.

---

### UX-007 — İade onay diyaloğu tutarı hiç göstermiyor, terminoloji Refund ile Cancel Transaction arasında çelişiyor ve kısmi iade seçeneği yok

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | destructive-action-ux |
| **Konum** | [admin/class-nicepay-transactions.php:924](../../../admin/class-nicepay-transactions.php#L924) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Tablodaki buton 'Refund' etiketli (929) ama aria-label'ı 'Cancel transaction %s' (816-822, 928) ve açtığı diyaloğun başlığı i18n.cancelTitle='Cancel Transaction', onay butonu i18n.cancelConfirm='Cancel Transaction' (admin/class-nicepay-admin.php:81, 86; assets/js/nicepay-admin.js:250, 254). Diyalogda gösterilen tek açıklama 'This action cannot be undone. The payment will be reversed.' (admin/class-nicepay-admin.php:82) — tutar, para birimi, sipariş numarası veya TID yok. Sunucu tarafında ajax_cancel_transaction() her zaman kalan bakiyenin TAMAMINI iade ediyor: $remaining = nicepay_normalize_ledger_amount(...); $amount = (float) $remaining; (1005-1011) ve wc_create_refund(... 'amount' => $amount ...) (1013-1019). Kısmi tutar girişi hiçbir yerde yok.
```

**Başarısızlık senaryosu**

Yönetici kısmen iade edilmiş (kalan 40.000 KRW) bir işlemin satırındaki Refund butonuna 'birazını iade edeyim' diyerek basıyor. Diyalog yalnızca sebep soruyor. Onaylıyor. 40.000 KRW'nin tamamı geri gidiyor ve işlem geri alınamıyor.

**Etki**

Geri alınamaz mali bir işlem, ne kadar paranın iade edileceği ekranda hiç yazmadan onaylatılıyor. Butonun etiketi (Refund), aria-label'ı (Cancel transaction) ve diyalog başlığı (Cancel Transaction) üç farklı kavramı ima ediyor; Kore PG bağlamında cancel (tam iptal) ile refund (kısmi/tam iade) farklı işlemler olduğu için bu önemsiz değil.

**Öneri**

(1) Butonu data-amount ve data-currency ile donat; modal mesajını sprintf ile kur: 'Bu islemden %s iade edilecek (Siparis #%s, TID %s). Bu islem geri alinamaz.' (2) Terminolojiyi tek kelimede sabitle — görünen her yerde 'iade' kullan, aria-label'ı da buna çevir. (3) Diyaloğa isteğe bağlı tutar alanı ekle (varsayılan ve max = kalan bakiye; CELLPHONE ve kısmi iadeye kapalı kart durumlarında salt-okunur) ve sunucuda mevcut kısmi iade kapılarından geçir. (4) Onay butonu metnini tutar içerecek şekilde üret ('40,000 KRW iade et').

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: İşlemler tablosundaki iade onay diyaloğu, iade edilecek tutarı/sipariş no/TID'yi hiç göstermiyor (yalnızca 'This action cannot be undone. The payment will be reversed.') ve terminoloji üç yerde çelişiyor: buton etiketi 'Refund' (transactions.php:929), aria-label 'Cancel transaction %s' (816-822, 928), modal başlığı ve onay butonu 'Cancel Transaction' (admin.php:81, 86). Ayrıca bu diyalog her zaman kalan bakiyenin TAMAMINI iade ediyor (transactions.php:1005-1019; $_POST['amount'] okunmuyor), kısmi tutar seçeneği sunmuyor. Not: kısmi iade ürünün genelinde destekleniyor — WooCommerce sipariş iade ekranı includes/class-nicepay-gateway.php:694 process_refund( $order_id, $amount, $reason ) üzerinden kısmi tutar geçirebiliyor (762-774 kapılar, 805 request_cancel $is_partial) — eksiklik yalnızca bu admin tablosu akışında. Ayrıca kalan tutar aynı tablo satırında görünür durumda (868-879), fakat onay anında diyalogda tekrarlanmıyor.
- Gerekçe: Kodu satır satır okudum; iddianın çekirdeği ve tüm satır referansları doğru. Butonun görünen etiketi 'Refund' (929), aria-label'ı 'Cancel transaction %s' (816-822, 928), modal başlığı ve onay butonu 'Cancel Transaction' (admin.php:81, 86 -> js:250, 254). Modal mesajı sadece 'This action cannot be undone. The payment will be reversed.' (admin.php:82) — tutar/para birimi/sipariş no/TID yok; JS handler yalnızca tid, id, nonce okuyor (js:244-247) ve modal'a tutarla ilgili hiçbir şey geçirmiyor. Sunucu tarafı gerçekten her zaman kalan bakiyenin tamamını iade ediyor (transactions.php:1005-1019); POST'ta 'amount' parametresi okunmuyor. Yani "geri alınamaz mali işlem, tutar gösterilmeden onaylatılıyor" iddiası doğrulandı.

İki detay düzeltmesi gerekiyor:
(1) "Kısmi tutar girişi hiçbir yerde yok" ifadesi fazla geniş. Kısmi iade ürün genelinde DESTEKLENİYOR: includes/class-nicepay-gateway.php:694 process_refund( $order_id, $amount, $reason ) WooCommerce'in kendi sipariş iade ekranından kısmi tutar alıyor ve 762-774'te CELLPHONE / kart / simple-pay için kısmi iade kapıları, 805'te request_cancel(..., $is_partial) var. Eksik olan şey, bu admin tablosundaki modalin kısmi tutar girişi sunmaması — "hiçbir yerde yok" değil, "bu diyalogda yok".
(2) Başarısızlık senaryosundaki "yönetici ne kadar iade edileceğini hiç göremiyor" kısmı yumuşatılmalı: aynı satırda 'Refunded: X · Remaining: Y' zaten yazılı (transactions.php:868-879). Yani tutar ekranda var ama ONAY DİYALOĞUNDA yok; klasik "destructive action confirmation'da bağlam yok" hatası, ama tam bir kör onay değil.

Bu iki düzeltme severity'yi high'tan medium'a çeker: eylem yine geri alınamaz ve terminoloji üç farklı kavram ima ediyor (Kore PG bağlamında cancel != refund), fakat tutar aynı satırda görünür ve akış WooCommerce refund kapılarından geçiyor. Öneriler (data-amount ile modal mesajı, terminoloji birleştirme, onay butonuna tutar) geçerli.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Transactions listesindeki "Refund" butonu yıkıcı, geri alınamaz bir para iadesini ONAY YÜZEYİNDE tutar göstermeden onaylatıyor: modal yalnızca sabit "This action cannot be undone..." metni ve sebep alanı içeriyor; tutar sadece modalın arkasında kalan tablo satırında ("Refunded: X · Remaining: Y", tx.php:872-877) görünüyor. Terminoloji üç yerde çelişiyor (buton "Refund" / aria-label "Cancel transaction %s" / modal başlığı ve onay butonu "Cancel Transaction"), üstelik modalın vazgeç butonu "Cancel", yıkıcı onay butonu "Cancel Transaction" olarak yan yana duruyor. Bu diyalogda kısmi tutar girişi yok ve sunucu (ajax_cancel_transaction, 970-1019) amount parametresini hiç kabul etmeyip her zaman kalan bakiyenin tamamını iade ediyor — kısmi iadenin kendisi eklentide destekleniyor (gateway supports 'refunds', process_refund kısmi kapıları 764-774) ancak yalnızca WooCommerce'in yerel sipariş ekranından erişilebiliyor.
- Gerekçe: Çekirdek iddianın tamamını kodda doğruladım: (a) buton etiketi "Refund", aria-label'ı "Cancel transaction %s", diyalog başlığı ve onay butonu "Cancel Transaction" — üç farklı terminoloji; (b) modal gövdesi yalnızca sabit mesaj + sebep input'u içeriyor, tutar/para birimi/sipariş/TID hiçbir yerde modalda basılmıyor (JS modal builder'ında yalnızca opts.message ve input DOM'a ekleniyor); (c) sunucu her zaman kalan bakiyenin tamamını iade ediyor, POST'ta amount parametresi ne okunuyor ne de gönderiliyor.

İstismar/üretilebilirlik lensi: senaryo gerçekten üretilebilir ve saldırgan gerektirmiyor — nicepay_current_user_can_manage_payments() yetkisine sahip bir yönetici, status'ü 'partially_refunded' olan bir satırdaki Refund butonuna basar (can_refund bu statüyü açıkça kabul ediyor, tx.php:803), modal yalnızca sebep sorar, onay sonrası wc_create_refund() kalan 40.000 KRW'nin tamamını iade eder. Ulaşılamaz kod yolu yok, önkoşullar gerçekçi. Dahası iddiada geçmeyen ek bir tehlike buldum: modalın kapatma butonu "Cancel" (i18n.cancel), yıkıcı onay butonu ise "Cancel Transaction" (i18n.cancelConfirm) — yan yana iki buton, biri vazgeç biri parayı geri gönder, neredeyse aynı metin. Bu, yanlış tıklama riskini iddia edilenden de artırıyor.

Düzeltilmesi gereken iki detay (bu yüzden "confirmed" değil "partially-confirmed"):
1. "Tutar hiç görünmüyor" tam doğru değil: aynı satırın Amount hücresinde "Refunded: X · Remaining: Y" zaten basılıyor (tx.php:872-877). Tutar ekranda vardır, ancak ONAY YÜZEYİNDE (modal, karartılmış overlay'in üstünde) yoktur — kritik nokta budur, iddia bunu fazla geniş ifade etmiş.
2. "Kısmi iade seçeneği yok" da kapsam olarak fazla geniş: eklenti gateway'de $this->supports = array('products','refunds') ve process_refund() içinde kısmi iade kapıları (CELLPHONE/kart/wallet için WP_Error'lar, gateway:764-774) mevcut — kısmi iade WooCommerce'in yerel sipariş ekranından yapılabiliyor. Eksik olan, bu transactions listesindeki diyalogda kısmi tutar girişi olması ve kullanıcının başka bir ekrana yönlendirilmemesi.

Severity: high seviyesini koruyorum ama gerekçesi kaydırılmalı — abartı değil çünkü geri alınamaz para hareketi, onay yüzeyinde tutar yok ve "Cancel"/"Cancel Transaction" buton çifti mis-click davetiyesi; hafife alınmış da değil çünkü admin-only, satırda tutar görünür ve alternatif kısmi iade yolu (Woo sipariş ekranı) mevcut. critical değil.

Öneri kısmındaki (3) maddesi de bu bulguya göre revize edilmeli: "sunucuda mevcut kısmi iade kapılarından geçir" doğru, ancak ajax_cancel_transaction() şu an amount parametresi kabul etmiyor — process_refund() zaten kısmi tutarı destekliyor, dolayısıyla iş AJAX katmanında tutarı okuyup doğrulamak (0 < amount <= remaining, KRW için tam sayı) ve wc_create_refund'a geçirmek.

---

### UX-001 — Defter durumlarının 11'inden 5'i için rozet CSS'i yok — needs_reconciliation dahil kritik durumlar görünmez render ediliyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | css-missing-state |
| **Konum** | [assets/css/nicepay.css:528](../../../assets/css/nicepay.css#L528) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```css
nicepay.css:528-544 yalnızca 6 varyant tanımlıyor: .nicepay-status-pending / -paid / -failed / -cancelled / -refunded / -waiting. Buna karşılık admin/class-nicepay-transactions.php:913 `class="nicepay-status nicepay-status-<?php echo esc_attr( $item->status ); ?>"` yazıyor ve allowed_statuses() (aynı dosya:276-278) 11 durum döndürüyor: pending, approving, paid, failed, partially_refunded, refunded, cancelled, needs_reconciliation, abandoned, expired, waiting. Taban .nicepay-status kuralı (nicepay.css:505-517) yalnızca padding/border-radius/uppercase/font-weight veriyor; hiç background veya color yok. ::before nokta göstergesi (520-526) `content:''` + boyut tanımlıyor ama background yalnızca varyant kurallarında geliyor.
```

**Başarısızlık senaryosu**

Bir onay yarıda kalıyor ve satır needs_reconciliation'a düşüyor. Yönetici NicePay > Transactions ekranını açıyor: 20 satırın 19'u renkli rozetle geliyor, uzlaştırma bekleyen satır ise şeffaf zeminde ince gri NEEDS RECONCILIATION metni olarak görünüyor — mali risk taşıyan tek satır görsel hiyerarşide en aşağıda kalıyor.

**Etki**

approving, partially_refunded, needs_reconciliation, abandoned, expired durumları arka planı ve rengi olmayan, göstergesi görünmez bir hap olarak çıkıyor. Tam da acil müdahale gerektiren needs_reconciliation satırı görsel olarak en sönük satır oluyor; mağaza sahibi tabloyu tararken bunları ayırt edemiyor.

**Öneri**

nicepay.css:544'ten sonra eksik beş varyantı ekle ve uzlaştırmayı en yüksek görsel ağırlıkla ver: .nicepay-status-approving { background:#e0f2fe; color:#075985; } / ::before { background:#0284c7; }; .nicepay-status-partially_refunded { background:#ede9fe; color:#5b21b6; } / ::before { background:#7c3aed; }; .nicepay-status-abandoned, .nicepay-status-expired { background:#f3f4f6; color:#4b5563; } / ::before { background:#9ca3af; }; .nicepay-status-needs_reconciliation { background:#fee2e2; color:#7f1d1d; box-shadow:inset 0 0 0 1px #ef4444; } / ::before { background:#dc2626; }. Ayrıca check-css.js'e allowed_statuses() içindeki her değer için .nicepay-status-<durum> kuralının varlığını doğrulayan bir kontrol ekle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: assets/css/nicepay.css yalnızca 6 durum rozeti varyantı tanımlıyor (528-544), oysa admin/class-nicepay-transactions.php:913 allowed_statuses() (:276-277) içindeki 11 durumun herhangi birini `.nicepay-status-<durum>` olarak basıyor. approving, partially_refunded, needs_reconciliation, abandoned, expired için ne background/color ne de ::before nokta rengi var; nicepay-admin.css'te de hiçbir status kuralı yok. Sonuç: bu beş durum, metni okunabilir (taban kuralda color tanımlı olmadığı için tablonun varsayılan koyu metin rengini miras alır) ama arka planı şeffaf ve nokta göstergesi görünmez bir "yarım rozet" olarak render olur — 6px'lik boş bir boşlukla. needs_reconciliation satırları en azından altlarındaki `<br><small>` uyarı notuyla (transactions.php:916-918) ve Refund butonunun gizlenmesiyle (:802) kısmen ayırt edilebiliyor, dolayısıyla "mali riskli satır tamamen görünmez" değil; sorun renk kodlamasının tutarsız olması ve tarama sırasında görsel hiyerarşinin kaybolması.
- Gerekçe: Çekirdek iddia kod tarafından birebir doğrulandı: CSS'te yalnızca 6 durum varyantı var, admin tablosu ise 11 durumdan herhangi birini sınıf adına basıyor; approving, partially_refunded, needs_reconciliation, abandoned, expired için hiçbir kural yok. Satır numaraları (nicepay.css:505-517 taban, 520-526 ::before, 528-544 varyantlar; transactions.php:913 render, :276-277 allowed_statuses) dosyaların şu anki haliyle tam eşleşiyor. İkinci bir CSS dosyası olan assets/css/nicepay-admin.css (1273 satır) içinde "status" geçen TEK bir kural yok — yani eksikliği telafi eden ikinci katman yok; her iki dosya da admin/class-nicepay-admin.php:56 ve :63'te birlikte enqueue ediliyor. Bu durumların gerçekten DB'ye yazıldığı da doğrulandı (nicepay-functions.php:675-676, 695, 933, 959 'approving'/'abandoned'/'expired'; gateway.php:853 'partially_refunded'). nicepay_get_status_label() (nicepay-functions.php:1679-1683) bu durumlar için insan-okur etiket döndürüyor, yani satır kesinlikle render ediliyor.

İki detay düzeltmesi gerekiyor, bu yüzden "confirmed" değil:
(1) "Görünmez render ediliyor" / "ince gri metin" abartılı. Taban .nicepay-status kuralında `color` YOK — yani metin WP admin tablosunun varsayılan koyu metin rengini miras alıyor ve 600 ağırlıkta, uppercase, letter-spacing'li olarak okunabilir kalıyor. Gerçekten görünmez olan tek şey ::before noktası (content:'' + 6x6px, background yalnızca varyant kurallarında), o da 6px'lik şeffaf bir boşluk bırakıyor. Sorun "okunamıyor" değil, "görsel hiyerarşide ayırt edilemiyor".
(2) needs_reconciliation için kısmi bir hafifletici var: transactions.php:916-918 bu satırlara rozetin altına `<br><small>` ile reconciliation_note / "Manual review required" ikinci satırı ekliyor (koşul :800-801'de tanımlı), ayrıca :802 Refund butonunu gizliyor. Yani "tamamen ayırt edilemez" değil — ama rozetin kendisi hâlâ renksiz.

Bu ikisi birlikte etkiyi fonksiyonel olmayan, salt görsel tutarsızlık seviyesine indiriyor: veri kaybı, yanlış aksiyon veya yanlış bilgi yok. high değil medium.

Ek olarak öneri kısmındaki check-css.js referansı geçerli: .github/scripts/check-css.js gerçekten mevcut ve içinde "status" ile ilgili hiçbir doğrulama yok, yani önerilen guard eklenebilir durumda.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Defter durumlarının 11'inden 5'i (approving, partially_refunded, needs_reconciliation, abandoned, expired) için rozet varyant CSS'i yok; bu satırlar arka planı ve nokta göstergesi olmayan, temaya göre renk mirası alan tutarsız "hap"lar olarak render ediliyor (nicepay.css:505-544 vs. transactions.php:913 + allowed_statuses() transactions.php:277). Aynı boşluk nicepay-admin.js:274-276'da var olmayan .nicepay-status-partially_refunded sınıfına da yansıyor. Ancak bu görsel tutarsızlık, needs_reconciliation için işlevsel bir kaçırma riski yaratmıyor: transactions.php:634-645 sayfanın tepesinde sayı ve doğrudan filtre linki içeren notice-error banner gösteriyor ve transactions.php:916-917 her uzlaştırma satırının altına açıklayıcı bir <small> notu ekliyor. Dolayısıyla bulgu gerçek bir cila/tutarlılık kusuru (medium), acil görünürlük hatası (high) değil. Önerilen düzeltme geçerli: eksik 5 varyantı ekle ve check-css.js'e (şu an yalnızca allowlist + csstree.parse yapıyor, satır 9-43) allowed_statuses() değerleri için sınıf varlık kontrolü koy.
- Gerekçe: Çekirdek teknik iddia doğrulandı: CSS'te yalnızca 6 durum varyantı var, PHP 11 durum render ediyor ve eksik 5 durumun (approving, partially_refunded, needs_reconciliation, abandoned, expired) tamamı gerçekten üretilebilir/veritabanına yazılıyor. Ancak istismar/sonuç merceğinden bakınca failure_scenario'nun "mali riskli satır görsel hiyerarşide en aşağıda kalır, yönetici ayırt edemez" kısmı abartılı ve kısmen yanlış.

Üretilebilirlik (doğrulandı):
- approving/abandoned: nicepay-functions.php:675-676, 695, 933; expired: 959; partially_refunded: gateway.php:853; needs_reconciliation: gateway.php:195, 517-518, return-handler.php:123-124. Hepsi ledger.status'a yazılıyor.
- Bu değerler doğrudan sınıf adına akıyor (transactions.php:913) ve nicepay.css admin ekranında yükleniyor (class-nicepay-admin.php:54-59, hook 'nicepay' içeriyorsa). Yani "ulaşılamaz kod yolu" değil; normal mağaza akışında (kullanıcı ödemeden vazgeçer -> abandoned/expired; kısmi iade -> partially_refunded) tetiklenir. Rol: manage_options/administrator, NicePay > Transactions ekranı.

Severity'yi düşürmemin gerekçesi (iddianın etki bölümünü çürüten kanıt):
1. needs_reconciliation satırları ekranın en tepesinde notice-error banner ile duyuruluyor: transactions.php:634-645, sayı + doğrudan `filter_status=needs_reconciliation` filtre linki. Yani "yönetici bunu kaçırır" senaryosu, iddianın varsaydığı gibi rozet rengine bağlı değil.
2. Satır bazında da ek metin ipucu var: transactions.php:916-917 `<small>` içinde reconciliation_note veya "Manual review required". needs_reconciliation satırı diğerlerinden fazladan bir satır metin ile ayrışıyor — "en sönük satır" değil.
3. Rozet metni kaybolmuyor: taban kural (nicepay.css:505-517) uppercase + font-weight:600 + letter-spacing veriyor, renk WP admin varsayılanından miras alınıyor. Sonuç "görünmez" değil, "arka planı olmayan/tutarsız hap" ve görünmez 6px nokta boşluğu. Bu bir tutarlılık/cilalanma kusuru, işlevsel körlük değil.

Dolayısıyla: gerçek, somut, düzeltilmesi gereken bir CSS eksikliği (üstelik nicepay-admin.js:275 hiç tanımlanmamış `.nicepay-status-partially_refunded` sınıfını kaldırmaya çalışıyor — gap'in ikinci kanıtı), ama high değil medium. Öneri kısmı (eksik varyantları eklemek + check-css.js'e kontrol) geçerli; check-css.js şu an gerçekten yalnızca dosya allowlist'i ve css-tree parse kontrolü yapıyor, sınıf varlığı doğrulaması yok (check-css.js:9-43).

Küçük konum düzeltmesi: allowed_statuses() 276-278 değil, gövde tek satır olarak transactions.php:277.

---

### UX-002 — .nicepay-notice taban sınıfının ve .nicepay-notice-info varyantının hiç görsel muamelesi yok — test modu bandı ve bilgi bildirimleri stilsiz düz metin

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | css-missing-state |
| **Konum** | [assets/css/nicepay.css:469](../../../assets/css/nicepay.css#L469) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```css
.nicepay-notice (nicepay.css:469-478) yalnızca padding, border-radius, margin-bottom, font-size, display:flex, gap ve animation tanımlıyor; background, color veya border YOK. Renkleri yalnızca .nicepay-notice-error (480-484) veriyor. Buna karşılık templates/payment-form.php:18-20 ve templates/standalone-payment-form.php:91-93 test modu uyarısını modifier'sız `<output class="nicepay-notice">` olarak basıyor. Ayrıca assets/js/nicepay.js:104-108 safeType 'info' olduğunda `.nicepay-notice nicepay-notice-info` sınıfı üretiyor; grep -n 'nicepay-notice-info' assets/css/ hiç sonuç vermiyor.
```

**Başarısızlık senaryosu**

Mağaza sahibi eklentiyi test modunda bırakıp sayfayı yayına alıyor. Müşteri ödeme formunu açıyor; test uyarısı sipariş özeti metniyle aynı renk ve tipografide sıradan bir satır olarak görünüyor, gözden kaçıyor. Müşteri odedim sanıyor, mağaza sahibi siparişi ücretsiz gönderiyor.

**Etki**

Ödeme formundaki 'Test mode — no real payment will be collected.' uyarısı tema gövde renginde, çerçevesiz, zeminsiz bir satır olarak çıkıyor — canlı görünen bir ödeme ekranındaki en önemli ayırt edici işaret fark edilmiyor. JS'in ürettiği bilgi bildirimleri de görünmez kalıyor. Test modunda toplanmayan bir ödemenin gerçek sanılması doğrudan mali risk.

**Öneri**

nicepay.css'e taban ve varyantları ekle: .nicepay-notice { background: var(--nicepay-bg); color: var(--nicepay-text); border:1px solid var(--nicepay-border); } .nicepay-notice-info { background: var(--nicepay-primary-light); color:#1e3a8a; border-color: var(--nicepay-primary-border); } .nicepay-notice-warning { background: var(--nicepay-warning-light); color:#7c2d12; border-color:#fcd34d; }. Şablonlarda test bandına nicepay-notice-warning ekle ve metni güçlendir. Böylece tanımlı ama hiç kullanılmayan --nicepay-warning / --nicepay-warning-light token'ları (nicepay.css:18-19) da ölü olmaktan çıkar.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: `.nicepay-notice` taban sınıfı (assets/css/nicepay.css:469-478) hiçbir background/color/border tanımlamıyor ve CSS'te tanımlı tek varyant `.nicepay-notice-error` (480-484). Bu yüzden templates/payment-form.php:17-21 ve templates/standalone-payment-form.php:91-94'teki modifier'sız test modu bandı, sipariş özeti metniyle aynı renk ve tipografide, yalnızca padding'li düz bir satır olarak çıkıyor — okunabiliyor ama hiçbir görsel vurgusu yok; canlı görünen bir ödeme ekranındaki en kritik ayırt edici işaret göz taramasında kayboluyor. Ayrıca CSS'te tanımlı `--nicepay-warning` / `--nicepay-warning-light` token'ları (18-19) hiçbir yerde kullanılmıyor ve assets/js/nicepay.js:108 tanımı olmayan `nicepay-notice-info` sınıfını üretebiliyor — ancak bu dal bugün ulaşılamaz durumda (nicepay.js:55, 74, 81'deki üç çağrının üçü de 'error' geçiyor), yani mevcut bir kullanıcı hatası değil, gelecekteki bir tuzak. Mağaza sahibi tarafında includes/nicepay-functions.php:507-513 test modu için admin uyarısı ürettiğinden, risk tamamen müşteri tarafındaki vurgusuzlukla sınırlı. İkincil tutarsızlık: payment-form.php:19 metni `<span>` sarmalayıcısı olmadan basıyor (standalone:92 basıyor) ve `.nicepay-notice-icon` (486-488) hiçbir şablonda kullanılmıyor.
- Gerekçe: CSS iddiasının tamamı kodla birebir doğrulandı; satır numaraları da dosyanın şu anki haliyle eşleşiyor.

DOĞRULANANLAR:
1. `.nicepay-notice` (nicepay.css:469-478) gerçekten yalnızca padding/border-radius/margin-bottom/font-size/display:flex/align-items/gap/animation tanımlıyor — background, color, border YOK. Renkleri yalnızca `.nicepay-notice-error` (480-484) veriyor.
2. `grep -rn "nicepay-notice" assets/ templates/ includes/ admin/` sonucuna göre CSS'te tanımlı tek varyant `-error`; `nicepay-notice-info` (ve `-warning`) hiçbir CSS dosyasında yok. Proje yalnızca iki CSS dosyası içeriyor (assets/css/nicepay.css, nicepay-admin.css) — başka bir katmanda telafi eden stil yok.
3. Her iki şablon da test modu bandını modifier'sız basıyor: templates/payment-form.php:17-21 ve templates/standalone-payment-form.php:91-94. Yani banner gerçekten sadece padding'li, tema gövde rengiyle, çerçevesiz/zeminsiz düz metin olarak çıkıyor.
4. `--nicepay-warning` / `--nicepay-warning-light` (nicepay.css:18-19) gerçekten ölü token: grep tüm assets/includes/admin/templates içinde yalnızca kendi tanım satırlarını buluyor.
5. assets/js/nicepay.js:108 gerçekten `'nicepay-notice nicepay-notice-' + safeType` üretiyor.

DÜZELTİLMESİ GEREKENLER (bu yüzden partially):
a) "JS'in ürettiği bilgi bildirimleri de görünmez kalıyor" iddiası bugün canlı bir kullanıcı etkisi DEĞİL: showNotice'in eklenti içindeki üç çağrısının (nicepay.js:55, 74, 81) üçü de `'error'` tipini geçiyor, dolayısıyla nicepay.js:104'teki `safeType` asla 'info' olmuyor. Bu, gerçek bir kullanıcı hatası değil, ulaşılamaz/ölü dal + gelecekteki tuzak.
b) "Görünmez kalıyor" ifadesi abartılı: metin render ediliyor ve okunabiliyor (flex konteynerdeki çıplak metin düğümü anonim flex item olur). Kusur görünürlük değil, VURGU/ayırt edilebilirlik eksikliği.
c) Başarısızlık senaryosundaki "mağaza sahibi farkında değil" kısmı kısmen zayıf: includes/nicepay-functions.php:507-513 ödeme yüzeyi açıkken test modu için admin uyarısı üretiyor. Müşteri tarafındaki vurgusuzluk yine de geçerli.
d) İkincil tutarsızlık (iddiada yok, doğru yönde ek kanıt): payment-form.php:19 metni `<span>` sarmalayıcısı olmadan, standalone-payment-form.php:92 ise `<span>` içinde basıyor; `.nicepay-notice-icon` hiçbir şablonda kullanılmıyor.

Bunlar birlikte, bulguyu gerçek ama tek başına finansal kırılma yaratmayan, saf CSS düzeyinde bir kusur haline getiriyor -> high yerine medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: `.nicepay-notice` taban sınıfı (nicepay.css:469-478) hiçbir background/color/border tanımlamıyor; renkleri yalnızca `.nicepay-notice-error` (480-484) veriyor. Her iki şablon da test modu bandını modifier'sız basıyor (payment-form.php:18-20, standalone-payment-form.php:91-93), bu yüzden band tema gövde renginde, çerçevesiz ve gövdeden küçük (0.875rem) bir blok olarak çıkıyor — okunabilir ama görsel vurgusu yok. Aynı şablonların hata bildirimlerini `nicepay-notice-error` ile doğru basması (standalone:43/49/55) bunun bilinçli değil tutarsızlık olduğunu gösteriyor. `--nicepay-warning` / `--nicepay-warning-light` (nicepay.css:18-19) tamamen kullanılmayan ölü token'lar.

DÜZELTME 1: `.nicepay-notice-info` için CSS eksikliği doğru ama ŞU AN ETKİSİZ — `showNotice`'ın üç çağrı yerinin de (nicepay.js:55, 74, 81) sabit `'error'` geçmesi nedeniyle nicepay.js:105'teki `'info'` dalı ulaşılamaz. Bu mevcut bir kullanıcı etkisi değil, gelecekteki bir çağrı için gizli tuzak (`confidence: low` seviyesinde raporlanmalı).

DÜZELTME 2 (iddianın kaçırdığı, daha ciddi kök neden): nicepay.js:102 `wrapper.find('.nicepay-notice').remove()` selector'ı çok geniş — ilk hata bildirimi gösterildiği anda test modu bandını da DOM'dan siliyor ve JS bandı asla geri getirmiyor. "Test modu göstergesi fark edilmiyor" riskinin asıl üretilebilir yolu budur. Düzeltme hem CSS varyantlarını eklemeli hem de bu selector'ı `.nicepay-notice-error, .nicepay-notice-info` ile daraltmalı (ya da banda ayrı bir `nicepay-test-banner` sınıfı verip remove'dan muaf tutmalı).
- Gerekçe: Çekirdek iddia doğru ve kodla kanıtlandı: `.nicepay-notice` taban sınıfı hiçbir background/color/border tanımlamıyor; renkleri yalnızca `-error` varyantı veriyor; her iki şablon da test modu bandını modifier'sız basıyor; `.nicepay-notice-info` için CSS yok; `--nicepay-warning*` token'ları gerçekten ölü. Konum (nicepay.css:469) ve tüm satır referansları dosyanın şu anki haliyle birebir eşleşiyor. CSS ayrıca gerçekten enqueue ediliyor (nicepay-payment-gateway.php:482-484), yani sorun "stil dosyası yüklenmiyor" değil, "kural yok".

Ancak istismar/sonuç merceğinden iki düzeltme gerekiyor:

(1) İDDİANIN "JS bilgi bildirimleri de görünmez kalıyor" kısmı ŞU AN ULAŞILAMAZ BİR KOD YOLU. `showNotice`'ın üç çağrı yerinin (nicepay.js:55-58, 74-77, 81-84) ÜÇÜ DE ikinci argüman olarak sabit `'error'` geçiyor; `assets/js/` genelinde başka çağıran yok (grep tüm dosyalarda yalnızca bu üç satırı döndürüyor). Dolayısıyla nicepay.js:105'teki `safeType = 'error' === type ? 'error' : 'info'` ifadesi pratikte hiç `'info'` üretmiyor. Bu, gerçekleşen bir kullanıcı etkisi değil, yalnızca ileriye dönük bir tuzak (dead branch). İddia bunu mevcut bir etki gibi sunuyor.

(2) SEVERITY ABARTILMIŞ. Üretilebilir tek somut senaryo şu: mağaza sahibi `nicepay_mode` = `test` bırakır, müşteri ödeme sayfasını açar, band `padding:12px 16px` + `display:flex` + `border-radius` ile blok olarak ama tema gövde rengiyle ve `font-size:0.875rem` (gövdeden KÜÇÜK) olarak çıkar. Yani metin görünür ve okunabilir; yalnızca görsel vurgusu yok. Bu, bir CSS/UX kusuru — girdiyle tetiklenen bir istismar veya kod hatası değil ve ön koşulu mağaza sahibinin yapılandırma hatasıdır. `high` yerine `medium` doğru seviye. Buna karşılık iddianın kaçırdığı ve etkiyi asıl büyüten şey var: nicepay.js:102 `wrapper.find('.nicepay-notice').remove()` — herhangi bir hata bildirimi gösterildiği anda test modu bandını da DOM'dan kalıcı olarak siliyor (band bir daha geri gelmiyor). Yani gerçek risk "stilsiz" olmasından çok "ilk hatadan sonra tamamen kaybolması". Bu, iddianın kendi failure_scenario'sundan daha somut ve daha üretilebilir bir yol.

Ek küçük tutarsızlık: standalone-payment-form.php:91-93 metni `<span>` içine sarıyor, payment-form.php:18-20 sarmıyor; `display:flex` altında ikisi farklı flex-item yapısı üretiyor.

---

### UX-003 — Kısakodun yedi hata dalı da CSS'i olmayan .nicepay-error sınıfı kullanıyor ve yönetici teşhis mesajlarını herkese açık ziyaretçiye sızdırıyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | error-messaging |
| **Konum** | [nicepay-payment-gateway.php:396](../../../nicepay-payment-gateway.php#L396) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
render_payment_shortcode() yedi ayrı hata dalında '<p class="nicepay-error">' döndürüyor: 396 (Standalone NicePay payments are not enabled.), 400, 409, 414 (Payment configuration not found.), 418, 423 (NicePay credentials are not configured.), 432. grep -rn 'nicepay-error' assets/css/ hiç sonuç vermiyor — bu sınıfın hiçbir stil kuralı yok. Ayrıca enqueue_payment_assets() ancak satır 403'te çağrılıyor, yani 396 ve 400'deki dallarda nicepay.css hiç yüklenmiyor. Aynı sayfadaki diğer hata çıktıları ise stilli .nicepay-notice nicepay-notice-error kullanıyor (templates/standalone-payment-form.php:43, 49, 55).
```

**Başarısızlık senaryosu**

Mağaza sahibi canlı moda geçiyor ama canlı merchant key'i girmeyi unutuyor. Ürün sayfasındaki [nicepay_payment id=x] kısakodu her ziyaretçiye stilsiz bir paragraf olarak 'NicePay credentials are not configured.' yazıyor. Müşteri ne yapacağını bilmiyor, sayfayı terk ediyor; wp-admin tarafında bu sayfaya özel hiçbir uyarı görünmüyor.

**Etki**

(1) Hata mesajları temanın düz paragraf stilinde, ikonsuz, zeminsiz çıkıyor. (2) 'NicePay credentials are not configured.' gibi yönetici teşhisleri son ziyaretçiye gösteriliyor; ziyaretçi için anlamsız, saldırgan için yapılandırma ipucu. (3) Mağaza sahibi hiçbir yönetici bildirimi almadığı için sorunu ancak müşteri şikayetiyle öğreniyor.

**Öneri**

Tek bir yardımcıya indirge: nicepay_shortcode_error($public_message, $admin_detail) — manage_options yetkisi varsa detayı, yoksa jenerik metni '.nicepay-notice nicepay-notice-error' kutusunda role=alert ile bas. Halka açık metin her dalda aynı ve eyleme dönük olsun: 'Odeme su anda kullanilamiyor. Lutfen magazayla iletisime gecin.' enqueue_payment_assets() çağrısını fonksiyonun başına al ki hata dallarında da CSS yüklensin. Bu dallar tetiklendiğinde bir transient/admin_notice ile mağaza sahibini uyar.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: render_payment_shortcode() gerçekten yedi hata dalında `<p class="nicepay-error">` döndürüyor (396, 400, 409, 414, 418, 423, 432) ve `.nicepay-error` seçicisi hiçbir CSS dosyasında tanımlı DEĞİL — dolayısıyla bu hatalar tema varsayılanı düz paragraf olarak, aynı sayfadaki stilli `.nicepay-notice nicepay-notice-error` kutularıyla tutarsız biçimde çıkıyor; ayrıca `role="alert"` yok (şablon karşılıklarında var). Bazı metinler ("NicePay credentials are not configured.", "Standalone NicePay payments are not enabled.") ziyaretçi için anlamsız yönetici teşhisi ve eyleme dönük değil. ANCAK iddianın iki yan dayanağı yanlış: (a) "396 ve 400 dallarında nicepay.css hiç yüklenmiyor" doğru değil — `wp_enqueue_scripts` kancasına bağlı `enqueue_scripts()` (satır 98/330) `is_payment_page()` ile post_content'te kısakod varsa zaten `enqueue_payment_assets()` çağırıyor; CSS yüklenir (yalnızca kısakod widget/şablon gibi $post->post_content dışından gelirse yüklenmez) ve zaten `.nicepay-error` kuralı olmadığı için bu fark sonucu değiştirmiyor. (b) "Mağaza sahibi hiçbir yönetici bildirimi almıyor" yanlış — `configuration_notice()` (satır 126, 1096 civarı) `nicepay_get_configuration_warnings()` üzerinden manage_options sahiplerine "NicePay live mode is selected, but the active MID or merchant key is missing. Payment forms remain unavailable." şeklinde bir `notice-error` basıyor. Geriye kalan gerçek kusur kozmetik tutarsızlık + zayıf/sızdıran halka açık metin + eksik role="alert" olduğundan severity high değil medium.
- Gerekçe: Çekirdek iddia doğrulandı: satır numaraları birebir tutuyor ve `.nicepay-error` sınıfı için CSS'te tek bir kural yok (yalnızca `--nicepay-error` CSS değişkeni ile ilgisiz `.nicepay-field-error` / `.nicepay-notice-error` seçicileri var; grep bunları "eşleşme" gibi gösterip yanıltabilir, ayrı ayrı kontrol ettim). Tutarsızlık da gerçek: aynı akıştaki şablon hataları `.nicepay-notice nicepay-notice-error` + `role="alert"` kullanıyor. Ancak iddianın "CSS hiç yüklenmiyor" ve "yönetici hiç uyarılmıyor" dayanaklarını kod çürütüyor; failure_scenario'nun son cümlesi ("wp-admin tarafında hiçbir uyarı görünmüyor") yanlış. Bu iki dayanak düştüğü için etki tabanı kozmetik + zayıf mesajlaşmaya iniyor; ödeme kaybı riski var ama mağaza sahibi zaten admin'de uyarıldığından high yerine medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: render_payment_shortcode() yedi hata dalında (nicepay-payment-gateway.php:396, 400, 409, 414, 418, 423, 432) hiçbir CSS kuralı bulunmayan `.nicepay-error` sınıfını ve `role="alert"` içermeyen düz bir <p> kullanıyor; aynı ödeme akışının template tarafı ise stilli, ikonlu ve `role="alert"` taşıyan `.nicepay-notice nicepay-notice-error` kutusunu kullanıyor (templates/standalone-payment-form.php:43, 49, 55). Sonuç: (a) görsel tutarsızlık — hata mesajları temanın düz paragraf stilinde, zeminsiz/ikonsuz çıkıyor; (b) erişilebilirlik boşluğu — ekran okuyucuya duyurulmuyor; (c) mesajlar ziyaretçi için eyleme dönük değil ("NicePay credentials are not configured." müşteriye ne yapacağını söylemiyor). İddianın iki destekleyici unsuru YANLIŞ: (1) nicepay.css bu dallarda da yükleniyor — enqueue_scripts()/is_payment_page() (satır 329-339, 794-800) kısakodu post_content içinde tespit edip CSS'i wp_enqueue_scripts aşamasında, shortcode render'ından önce enqueue ediyor; enqueue_payment_assets()'in 403'te olması yalnızca widget/do_shortcode gibi $post dışı bağlamlarda önem taşıyor. (2) Mağaza sahibi uyarısız kalmıyor — configuration_notice() (satır 126, 257-268) + nicepay_get_configuration_warnings() (includes/nicepay-functions.php:515-521) tam da bu senaryo için tüm wp-admin sayfalarında bir notice-error basıyor. Sızan bilgi ("kimlik bilgileri yapılandırılmamış") istismar edilebilir bir yapılandırma ipucu değil; bu bir güvenlik değil, mesajlaşma/tutarlılık bulgusudur.
- Gerekçe: Çekirdek iddia doğru ve kodla kanıtlandı: yedi hata dalı da hiçbir CSS kuralı olmayan `.nicepay-error` sınıfını kullanıyor, `role="alert"` yok ve aynı akışın diğer yarısı (template) stilli `.nicepay-notice nicepay-notice-error` kullanıyor. Tutarsızlık ve stilsizlik gerçek. Ancak iddianın üç destekleyici unsuru koda dayanmıyor:

(1) ENQUEUE ALT-İDDİASI BÜYÜK ÖLÇÜDE ÇÜRÜTÜLDÜ. "396 ve 400'deki dallarda nicepay.css hiç yüklenmiyor" yanlış. `enqueue_scripts()` (satır 329-339) `wp_enqueue_scripts` üzerinde çalışıyor ve `is_payment_page()` (794-800) `has_shortcode( $post->post_content, 'nicepay_payment' )` ile kısakodu içerik içinde tespit edip CSS'i shortcode render'ından ÖNCE yüklüyor. Yani senaryonun kendisinde (ürün/sayfa içeriğine gömülü kısakod) nicepay.css zaten sayfada. Alt-iddia sadece kenar durumlarda (widget, template'ten do_shortcode, $post dışı bağlam) geçerli. Zaten sınıfın hiç kuralı olmadığı için CSS'in yüklenip yüklenmemesi bu paragrafların görünümünü değiştirmiyor — iki gerekçe birbirini geçersiz kılıyor.

(2) "MAĞAZA SAHİBİ HİÇBİR YÖNETİCİ BİLDİRİMİ ALMIYOR" ÇÜRÜTÜLDÜ — ve bu, failure_scenario'nun tam olarak seçtiği senaryoda çürüyor. Satır 126'da `add_action( 'admin_notices', array( $this, 'configuration_notice' ) )` kayıtlı; `configuration_notice()` (257-269) `nicepay_get_configuration_warnings()` sonuçlarını basıyor ve includes/nicepay-functions.php:515-521 tam da "canlı moda geçip live merchant key girmeyi unutmak" durumu için `notice-error` üretiyor: "NicePay live mode is selected, but the active MID or merchant key is missing. Payment forms remain unavailable." Bu uyarı tüm wp-admin sayfalarında görünüyor. İddianın "wp-admin tarafında hiçbir uyarı görünmüyor" cümlesi yanlış; doğrusu "sayfaya-özel bir uyarı yok, ama global bir yönetici hata bildirimi var".

(3) İSTİSMAR EDİLEBİLİRLİK / BİLGİ SIZINTISI ABARTILMIŞ. Erişilebilirlik gerçek: anonim bir ziyaretçinin sayfayı GET etmesi yeterli, ön koşul yok (423 dalı için: HTTPS + geçerli kayıtlı config + boş mode/mid/key). Ancak sızan içerik "NicePay credentials are not configured." cümlesinden ibaret — bir saldırgan için istismar edilebilir bir yapılandırma ipucu değil; sadece eklentinin kurulu ve yapılandırılmamış olduğunu söylüyor, ki bunu zaten sayfadaki kısakod varlığı ima ediyor. Kimlik bilgisi, dosya yolu, sürüm, MID veya iç durum sızmıyor. Bu bir güvenlik bulgusu değil, UX/mesajlaşma bulgusu.

Sonuç: kategori (error-messaging) doğru, konum doğru, ama etki "high" değil. Ödeme kaybı gerçek ama nedeni stilsizlik değil, mesajın eyleme dönük olmaması; mağaza sahibi zaten uyarılıyor. severity: medium.

---

### UX-005 — Standalone ödeme akışında erişilebilir hiçbir yükleniyor/ilerleme geri bildirimi yok; butonun metni de görünmez oluyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | accessibility-status |
| **Konum** | [templates/standalone-payment-form.php:178](../../../templates/standalone-payment-form.php#L178) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
WooCommerce şablonunda tam ekran bir yükleniyor katmanı var (templates/payment-form.php:23-26, role="alert" aria-live="assertive" + görünür metin). Standalone şablonunda bu blok HİÇ yok — 178-186 arasında yalnızca gönder butonu var. setStandaloneLoading() (assets/js/nicepay.js:152-160) sadece button.disabled=true ve classList.toggle('is-loading') yapıyor. CSS'te .nicepay-pay-button.is-loading (nicepay.css:253-256) color: transparent !important uygulayıp yerine yalnızca dekoratif bir ::after halka koyuyor (258-268). Hiçbir yerde aria-live, aria-busy veya role=status yok.
```

**Başarısızlık senaryosu**

NVDA kullanan bir alıcı standalone formda Öde'ye basıyor. 3 saniyelik AJAX turunda ekran okuyucu hiçbir şey söylemiyor. Kullanıcı işlemin başarısız olduğunu varsayıp sayfayı yeniliyor — sunucuda pending bir attempt kalıyor.

**Etki**

Ekran okuyucu kullanıcısı Öde'ye bastıktan sonra AJAX turu ve NicePay penceresinin açılışı boyunca sıfır geri bildirim alıyor; buton devre dışı kaldığı için odak da kaybolabiliyor. Gören kullanıcı için de buton metni şeffaf hale geldiğinden büyütme/yüksek kontrast kullananlarda buton boşalmış görünüyor. Yavaş bağlantıda ikinci tıklama 'Another payment is already in progress.' hatasını tetikliyor.

**Öneri**

(1) Standalone sarmalayıcıya görsel gizli bir canlı bölge ekle: <p class="screen-reader-text" id="...-status" role="status" aria-live="polite"></p>. (2) setStandaloneLoading(form,true) içinde bu bölgeye i18n.processing yaz, false'ta temizle. (3) Butona aria-busy ekle ve color:transparent yerine metni i18n.processing ile değiştir. (4) En ucuz çözüm: WooCommerce yolundaki .nicepay-loading-overlay bloğunu standalone şablonda da render et.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Standalone ödeme akışında (templates/standalone-payment-form.php:178-186) işlem sürerken erişilebilir bir durum bildirimi yok: sarmalayıcıda değişen bir canlı bölge (role=status / aria-live) bulunmuyor ve setStandaloneLoading (assets/js/nicepay.js:152-160) yalnızca butonları disabled edip .is-loading sınıfı ekliyor, aria-busy yazmıyor. CSS (assets/css/nicepay.css:253-256) buton metnini color: transparent !important ile gizleyip yerine yalnızca dekoratif bir ::after halkası koyuyor; prefers-reduced-motion altında (581-589) o halka da durduğu için buton tamamen boş görünüyor. Aynı eklentinin WooCommerce şablonu (templates/payment-form.php:23-26) role="alert" aria-live="assertive" + görünür "Processing payment..." metni içeren tam bir katman render ettiği için bu, iki akış arasında doğrudan bir tutarsızlık. DÜZELTME: "ikinci tıklama 'Another payment is already in progress.' hatasını tetikliyor" kısmı yanlıştır — aynı formda buton disabled + pointer-events:none olduğundan ve nicepay.js:361'deki kontrol `activePaymentForm !== form` şartına bağlı olduğundan bu mesaj yalnızca sayfada birden fazla standalone form varken ortaya çıkabilir. Ayrıca hata durumunda showStandaloneError (nicepay.js:171-176) role="alert" ile anons yapmaktadır; eksik olan yalnızca "işlem sürüyor" ara durumudur (WCAG 2.1 AA, 4.1.3 Status Messages).
- Gerekçe: Çekirdek iddia doğrulandı: standalone şablonda yükleniyor durumu için hiçbir erişilebilir bildirim mekanizması yok ve buton metni CSS ile görünmez yapılıyor. Tüm dosya+satır referansları dosyaların şu anki haliyle bire bir eşleşiyor (templates/standalone-payment-form.php:178-186, assets/js/nicepay.js:152-160, assets/css/nicepay.css:253-256 ve 258-268, templates/payment-form.php:23-26). İddiayı çürütecek bir koruma aramak için grep'ledim: standalone şablondaki TEK aria-live, satır 91'deki test-modu bildirimi ve o statik içerik hiç değişmiyor, dolayısıyla canlı bölge işlevi görmüyor; JS tarafında aria-busy/role=status hiç kullanılmıyor (yalnızca assets/js/nicepay-admin.js'te var — yani kod tabanı bu deseni biliyor ama ön yüz standalone akışında uygulamamış, bu da tutarsızlık iddiasını güçlendiriyor). Erken return / üst katman koruması yok.

İki detay yanlış, bu yüzden partially-confirmed:
1) "Yavaş bağlantıda ikinci tıklama 'Another payment is already in progress.' hatasını tetikliyor" iddiası aynı form için YANLIŞ. setStandaloneLoading (nicepay.js:153-156) sayfadaki tüm [data-nicepay-start] butonlarını disabled yapıyor ve CSS .is-loading kuralı pointer-events:none uyguluyor (nicepay.css:255). Ayrıca startStandalone'daki kontrol `activePaymentForm && activePaymentForm !== form` (nicepay.js:361) olduğu için aynı formun ikinci tıklaması zaten o dalı almaz. Bu mesaj yalnızca sayfada BİRDEN FAZLA standalone form varken, ikinci bir forma tıklanınca çıkar. Ancak butonların disabled edilmesi ve ikinci formun butonunun da disabled olması nedeniyle bu bile pratikte klavye/fare ile zor tetiklenir.
2) Hata sonucu için "sıfır geri bildirim" tam doğru değil: showStandaloneError (nicepay.js:171-176) role="alert" ile bildirim basıyor, yani BAŞARISIZLIK anonsu var. Eksik olan yalnızca "işlem sürüyor" ara durumu (WCAG 4.1.3 Status Messages).

Bu nedenle severity'yi high yerine medium'a çekiyorum: görsel spinner mevcut, hata durumu anons ediliyor, etki penceresi AJAX turu + pencere açılışıyla sınırlı. Yine de gerçek bir WCAG 4.1.3 ihlali ve WooCommerce yoluyla açık bir tutarsızlık; öneri (özellikle 4. madde — .nicepay-loading-overlay bloğunu standalone'da da render etmek) geçerli. Buton metninin color:transparent !important ile silinmesi ve yerine yalnızca dekoratif ::after konması da doğrulandı; prefers-reduced-motion bloğu (nicepay.css:581-589) .nicepay-payment-wrapper * kapsadığı için spinner animasyonu duruyor, yani reduced-motion kullanıcısında buton HEM boş HEM hareketsiz kalıyor — bu, iddiayı zayıflatmıyor, aksine güçlendiriyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Standalone ödeme akışında "işleniyor" durumu için erişilebilir bir durum bildirimi (aria-live / role=status / aria-busy) yok; WooCommerce şablonundaki `.nicepay-loading-overlay` (role="alert" aria-live="assertive" + görünür metin) standalone şablonda hiç render edilmiyor. Ayrıca `setStandaloneLoading()` odaklı butonu `disabled` yaparak odağı `<body>`'ye düşürüyor ve CSS `color: transparent !important` ile buton metnini görünmez kılıyor. ANCAK iddianın "yavaş bağlantıda ikinci tıklama 'Another payment is already in progress.' hatasını tetikliyor" kısmı üretilemez: `setStandaloneLoading()` sayfadaki TÜM `[data-nicepay-start]` butonlarını disable ediyor (nicepay.js:153-155) ve CSS `.is-loading { pointer-events: none }` (nicepay.css:255) ekliyor; nicepay.js:361-364'teki `paymentInProgress` dalı yalnızca `activePaymentForm` FARKLI bir form iken tetiklenir, ki bu durumda o formun butonu da zaten disabled'dır. Ayrıca hata durumlarının tamamı `showStandaloneError()` ile `role="alert"` olarak duyuruluyor (nicepay.js:171-193), yani "sıfır geri bildirim" yalnızca BEKLEME süresi için geçerli; hata sonunda ekran okuyucu bilgilendiriliyor. Bu nedenle bulgu gerçek bir WCAG 2.1 SC 4.1.3 (Status Messages, AA) ihlali ama "high" değil "medium".
- Gerekçe: Çekirdek iddia kod okumasıyla doğrulandı: standalone şablonda yükleniyor katmanı yok, JS sadece disabled+class toggle yapıyor, CSS buton metnini şeffaflaştırıyor, hiçbir yerde aria-busy/role=status kullanılmıyor.

Ancak istismar edilebilirlik/sonuç merceğinden üç düzeltme gerekiyor:

1) İKİNCİ TIKLAMA SENARYOSU ULAŞILAMAZ. `setStandaloneLoading` `document.querySelectorAll('[data-nicepay-start]')` üzerinde dönerek sayfadaki tüm başlat butonlarını disable ediyor; üstüne CSS `pointer-events: none` var. `paymentInProgress` dalına ulaşmak için `activePaymentForm && activePaymentForm !== form` gerekiyor — yani ikinci bir standalone formun butonuna tıklamak — ama o buton da disabled. Bu dal pratikte yalnızca WooCommerce ödeme sayfası ile bir standalone shortcode aynı sayfada birlikte render edilirse (gerçekçi olmayan bir kurulum) veya `hideLoading()` jQuery ile `$('.nicepay-pay-button').prop('disabled', false)` çağırıp standalone butonlarını yanlışlıkla geri açarsa erişilebilir. İddia bunu tipik bir akış gibi sunuyor; değil.

2) "SIFIR GERİ BİLDİRİM" ABARTILI. Bekleme sırasında evet, sıfır. Ama tüm sonlanma yolları (`connectionError`, `sessionExpired`, `initializationFailed`, `unexpectedError`, `systemUnavailable`) `showStandaloneError()` → `role="alert"` ile duyuruluyor. NVDA kullanıcısı 3 saniyelik AJAX turunda sessizlik yaşar, ama tur bir hatayla biterse bunu DUYAR; başarıyla biterse NicePay penceresi (window.nicepayStart) odağı alır. "Kullanıcı başarısız sandı, sayfayı yeniledi, sunucuda pending attempt kaldı" senaryosu ancak kullanıcı 3 saniyeden önce sabırsızlanırsa gerçekleşir — mümkün ama kesin değil.

3) "HİÇBİR YERDE aria-live YOK" tam doğru değil. Standalone şablonda test modunda `<output class="nicepay-notice" aria-live="polite">` var (satır 91-93). Fakat bu bölgeye JS asla yazmıyor (grep: nicepay.js'te bu output'a hiçbir referans yok) ve yalnızca test modunda render ediliyor — yani işlevsel olarak iddianın sonucu ("kullanılabilir bir canlı bölge yok") doğru, kanıt cümlesi eksik.

DOĞRULANAN EK ETKİ (iddiada zayıf geçmiş, aslında gerçek): odaklı buton `disabled` yapıldığında tarayıcı odağı `<body>`'ye düşürür; `releaseStandalone()` (nicepay.js:162-168) butonu tekrar enable ederken odağı GERİ VERMİYOR. Yani hata sonrası klavye kullanıcısı sekme sırasının başına döner. Bu, iddiadaki "odak kaybolabiliyor" ifadesinden daha kesin bir kusur.

Severity gerekçesi: güvenlik/veri kaybı yok, görsel spinner mevcut, hatalar duyuruluyor. WCAG AA seviye ihlali + ödeme akışında olması nedeniyle "low" değil; ama istismar edilebilir bir başarısızlık üretmediği ve düzeltmesi tek satırlık olduğu için "high" da değil → medium.

Öneri kısmı (özellikle 4. madde: mevcut `.nicepay-loading-overlay` bloğunu standalone şablonda da render et) geçerli ve en ucuz düzeltme. Buna ek olarak `releaseStandalone` içinde butonu yeniden enable ettikten sonra `button.focus()` çağrılmalı.

---

### UX-006 — Yönetici işlem tablosu wp-list-table sınıflarını kullanıyor ama column-primary / data-colname / toggle-row sözleşmesini uygulamıyor — 782px altında tablo kullanılamıyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | responsive |
| **Konum** | [admin/class-nicepay-transactions.php:749](../../../admin/class-nicepay-transactions.php#L749) |
| **Güven** | medium |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Tablo <table class="wp-list-table widefat fixed striped"> olarak açılıyor (749) ve 9 sütunu var (753-761). Ancak hiçbir <td>'de class="column-primary", data-colname="..." niteliği ya da WP'nin <button type="button" class="toggle-row"> satır açma düğmesi yok (837-941 arasındaki tüm hücreler çıplak <td>). Eklentinin kendi duyarlı kuralı yalnızca tabloyu kaydırılabilir yapıyor: assets/css/nicepay-admin.css:1211-1215 .nicepay-admin .wp-list-table { display:block; overflow-x:auto; } — hücrelere dokunmuyor. WordPress çekirdeğinin list-tables.css'i ise 782px altında hücreleri block'a çevirip birincil olmayan sütunları data-colname etiketine ve .is-expanded durumuna bağlıyor.
```

**Başarısızlık senaryosu**

Mağaza sahibi telefondan bir iadeyi onaylamak istiyor. NicePay > Transactions'ı açıyor; başlık satırı görünüyor ama satır içerikleri ya görünmüyor ya da Refund/Details butonları etiketsiz bloklar halinde alt alta diziliyor; hangi butonun hangi işleme ait olduğu anlaşılmıyor.

**Etki**

Tabletten/telefondan wp-admin'e giren mağaza sahibi işlem defterini ya tamamen boş (çekirdek birincil olmayan hücreleri gizlediği ve açma düğmesi olmadığı için) ya da başlıksız üst üste yığılmış hücreler olarak görüyor. Tabloyu okunur kılan tek şey 9 sütunun yatay kaydırılması; 360px'te fixed tablo düzeni her sütunu ~40px'e sıkıştırıyor. İade yapmak için kullanılan tek ekran mobilde işlevsiz.

**Öneri**

(A) Sözleşmeyi tamamla: her <td>'ye data-colname ekle, birincil sütunu <td class="has-row-actions column-primary" data-colname="..."> yap ve satır sonuna WP'nin standart toggle-row düğmesini koy; böylece çekirdeğin mobil davranışı ve klavye desteği ücretsiz gelir. (B) Alternatif: wp-list-table sınıfını bırakıp 782px altında kendi kart düzenini yaz (her satır bir kart, alan adları ::before { content: attr(data-label) } ile). Uzun vadede WP_List_Table sınıfına geçmek bakım yükünü de düşürür.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Yönetici işlem tablosu wp-list-table sınıflarını kullanıyor ama WP'nin duyarlı sözleşmesini (column-primary / data-colname / toggle-row) uygulamıyor; bu yüzden 782px altında çekirdeğin koşulsuz `tr { display:flex; flex-wrap:wrap }` ve `td:nth-child(n+3) { flex:0 1 100% }` kuralları devreye girip 3.-9. sütunları ETİKETSİZ olarak alt alta yığar, başlık satırı da hücrelerle hizasız sarmalanır ve plugin'in `overflow-x:auto` kuralı sütun düzenini kurtaramaz. Hücreler gizlenmez (gizleme kuralı `td.column-primary ~ td` kardeş seçicisine bağlıdır ve hiç eşleşmez), Refund/Details butonları görünür kalır — yani tablo boş değil, okunması zor ve etiketsizdir.
- Gerekçe: Kod okundu; iddianın ÇEKİRDEĞİ doğru: tablo wp-list-table sınıfıyla açılıyor, 9 sütunu var ve WP'nin duyarlı sözleşmesinden (column-primary / data-colname / toggle-row) HİÇBİRİ uygulanmamış — repo genelinde bu üç ifade tek bir kez bile geçmiyor. Satır numaraları da doğru (749, 753-761, 837-941, CSS 1211-1215). CSS admin sayfalarında gerçekten enqueue ediliyor (admin/class-nicepay-admin.php:61-63) ve tablo `<div class="wrap nicepay-admin">` (satır 598) içinde, yani plugin'in `.nicepay-admin .wp-list-table { display:block; overflow-x:auto }` kuralı uygulanıyor.

ANCAK iddianın MEKANİZMA ve ETKİ kısmı yanlış. WP core list-tables.css'i (trunk'tan indirildi, /tmp/lt.css) 782px altında hücreleri şu selector'la gizler:

    .wp-list-table th.column-primary ~ th,
    .wp-list-table tr:not(.inline-edit-row):not(.no-items) td.column-primary ~ td:not(.check-column),
    .wp-list-table tr:not(.inline-edit-row):not(.no-items) th.column-primary ~ td:not(.check-column) { display: none; }

Gizleme tamamen `column-primary` sınıfının VARLIĞINA bağlı bir kardeş (~) seçicisidir. Bu tabloda hiçbir hücrede column-primary olmadığı için bu kural HİÇ eşleşmez — dolayısıyla "tablo tamamen boş görünür / çekirdek birincil olmayan hücreleri gizler" iddiası YANLIŞTIR. Hiçbir hücre gizlenmez. Aynı şekilde `padding-left:35%` etiket boşluğu kuralı da yalnızca `td.column-primary ~ td` için tanımlı olduğundan uygulanmaz; `::before { content: attr(data-colname) }` kuralı uygulanır ama attr boş dönüp yüksekliği 0 olan görünmez bir kutu üretir (görsel bir bozulma değil).

Gerçekte olan: core'un koşulsuz `.wp-list-table tr { display:flex; flex-wrap:wrap }` ve `.wp-list-table tr td:nth-child(n+3) { flex: 0 1 100% }` kuralları uygulanır. Yani 782px altında ilk iki hücre (ID, TID) yan yana kalır, 3.-9. hücreler (Order, Balance, Method, Status, Buyer, Date, Actions) tam genişlikte, ETİKETSİZ olarak alt alta yığılır; thead satırı da flex-wrap olduğu için 9 başlık gövde hücreleriyle hizasız bir blok halinde sarmalanır. Plugin'in `overflow-x:auto` kuralı da işe yaramaz, çünkü satırlar zaten flex ile yığıldığından yatay kaydırma sütun düzenini korumaz — yani "tabloyu okunur kılan tek şey yatay kaydırma" ve "360px'te fixed düzen sütunları 40px'e sıkıştırır" ifadeleri de yanlış; ≤782px'te fixed tablo düzeni zaten devre dışıdır.

Sonuç: mobil deneyim gerçekten bozuk (etiketsiz yığılmış hücreler, başlık-hücre kopukluğu, toggle-row yok) ve önerilen düzeltme (A/B) geçerli — ama "boş tablo / iade ekranı işlevsiz" abartılıdır: Refund ve Details butonları son hücrede görünür ve tıklanabilir kalır. Bu yüzden high değil medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Yönetici işlem tablosu `wp-list-table` sınıfını alıyor (admin/class-nicepay-transactions.php:749) ama `column-primary` / `data-colname` / `toggle-row` sözleşmesinin hiçbir parçasını uygulamıyor (836-942 arasındaki tüm hücreler çıplak `<td>`; depoda bu üç kelime hiç geçmiyor). Eklentinin tek responsive kuralı (assets/css/nicepay-admin.css:1205-1215) yalnızca `<table>` elemanını `display:block; overflow-x:auto` yapıyor, hücrelere dokunmuyor. Sonuç: viewport 782px altına düştüğünde (telefon/tablet VE dar masaüstü penceresi) WP çekirdeğinin list-tables.css'i birincil olmayan tüm `td` VE `th`'leri gizler; hiçbir hücre birincil işaretlenmediği için tablo başlıklarıyla birlikte pratikte tamamen boş görünür ve satırı açacak `toggle-row` düğmesi de yoktur. "Etiketsiz yığılmış hücreler" varyantı gerçekleşmez — görünen şey boş tablodur. İade işlemi tamamen engellenmez: includes/class-nicepay-gateway.php:694'teki `process_refund()` sayesinde WooCommerce'in yerleşik sipariş ekranı iadesi hâlâ kullanılabilir; engellenen şey NicePay işlem defterinin mobilde okunabilirliğidir. Karşılaştırma kanıtı: 717. satırdaki özet tablosu `wp-list-table` sınıfını almadığı için aynı breakpoint'te sorunsuz çalışıyor — bu da en düşük maliyetli düzeltmenin 749'daki `wp-list-table` sınıfını kaldırıp mevcut yatay kaydırmaya bırakmak, ya da sözleşmeyi eksiksiz uygulamak olduğunu gösteriyor.
- Gerekçe: Kodun kendisi iddiayı doğruluyor; ancak sonuç/istismar edilebilirlik merceğinde iki detay yanlış ve severity bir kademe abartılmış.

DOĞRULANAN ÇEKİRDEK:
1. Tablo gerçekten `wp-list-table` sözleşmesine giriyor (admin/class-nicepay-transactions.php:749) ve 9 sütunlu (753-761).
2. Depoda `column-primary`, `data-colname` ve `toggle-row` kelimeleri HİÇ geçmiyor. `grep -rn "wp-list-table\|column-primary\|data-colname\|toggle-row" admin includes assets templates` yalnızca 7 satır döndürdü ve hepsi `wp-list-table` eşleşmesi — sözleşmenin diğer üç parçası için sıfır hit. 836-942 arasındaki tüm hücreler gerçekten çıplak `<td>` (tek istisna sunum amaçlı `class="nicepay-amount"` 868 ve `colspan="9" class="nicepay-empty-state"` 767).
3. Eklentinin tüm CSS dosyasında (1273 satır) TEK bir media query var: `@media (max-width: 782px)` (assets/css/nicepay-admin.css:1205) ve içindeki tek tablo kuralı (1211-1215) sadece `<table>` elemanını `display:block; overflow-x:auto` yapıyor. Dosyada `table-cell`, `::before` veya `data-label` içeren tek bir satır bile yok — yani hücre seviyesinde ne çekirdeği ezen ne de kendi kart düzenini kuran bir kural mevcut. CSS 782px'te wp-admin'in kendi breakpoint'iyle birebir aynı, dolayısıyla çekirdek kuralları da tam bu noktada devreye giriyor.
4. Stil gerçekten yükleniyor (admin/class-nicepay-admin.php:62-63) ve sarmalayıcı `.nicepay-admin` mevcut (598), yani plugin kuralı uygulanıyor; ancak wp-admin'in `wp-admin` stil paketi `list-tables` bağımlılığını her yönetim sayfasında yüklediği için çekirdek mobil kuralları da uygulanıyor.

DÜZELTİLEN DETAYLAR (sonuç merceği):
A) "Başlık satırı görünüyor" YANLIŞ. WP çekirdeği 782px altında yalnızca hücreleri değil, birincil olmayan `thead th`'leri de gizler (mobilde Posts listesinde sadece "Title" başlığının görünmesinin sebebi budur). Hiçbir hücre `column-primary` olmadığı için hem başlıklar hem gövde hücreleri `:not(.column-primary)` eşleşmesine girer: sonuç "etiketsiz yığılmış hücreler" değil, PRATİKTE TAMAMEN BOŞ bir tablo (yalnızca sr-only caption ve sayfalama görünür). Yani "ya/ya da" ifadesindeki ilk şık geçerli, ikinci şık gerçekleşmez.
B) "İade yapmak için kullanılan TEK ekran" YANLIŞ. includes/class-nicepay-gateway.php:694 `public function process_refund( $order_id, $amount = null, $reason = '' )` tanımlı; bu, WooCommerce'in kendi sipariş düzenleme ekranındaki yerleşik iade arayüzünü çalışır kılar. Mağaza sahibi mobilden iadeyi WC sipariş ekranından yapabilir. Bu, "mobilde iade imkânsız" iddiasını değil, "bu ekran mobilde işlevsiz" iddiasını bırakır.
C) Erişim genişliği İDDİADAN DAHA GENİŞ: 782px eşiği yalnızca telefon/tablet değil, dar bir masaüstü tarayıcı penceresinde de tetiklenir. Yani önkoşullar fazlasıyla gerçekçi — herhangi bir yönetici/mağaza yöneticisi rolü, ek bir istek veya zamanlama gerekmeden GET ile `admin.php?page=nicepay-transactions` açıp pencereyi daraltmak yeterli.

Ek gözlem (kanıt): 717. satırdaki özet tablosu `class="widefat striped"` — `wp-list-table` YOK, dolayısıyla çekirdeğin mobil sözleşmesine hiç girmez ve 782px altında sorunsuz görünür. Bu, 749'daki tablonun `wp-list-table` sınıfını kazara/kozmetik amaçla eklediğini gösteriyor; en ucuz düzeltme aslında öneri (A)/(B) değil, bu tabloda da `wp-list-table` sınıfını düşürüp mevcut `overflow-x:auto` kaydırmasına bırakmak.

SEVERITY: `high` -> `medium`. Veri kaybı, güvenlik veya yanlış tutar yok; masaüstünde ekran tam çalışıyor ve mobil iade için WooCommerce sipariş ekranı alternatifi mevcut. Yine de tetiklenmesi kolay, tamamen görsel bozulma ürettiği ve düzeltmesi tek satırlık olduğu için `low` değil `medium`.

BELİRSİZLİK NOTU: WP çekirdeğinin list-tables.css dosyası bu makinede bulunamadı (`find / -name list-tables.css -path "*wp-admin*"` sonuçsuz), dolayısıyla çekirdek kural metnini birebir alıntılayamadım; çekirdek davranışı hafızadan (mobil Posts/Orders listelerinin bilinen davranışı) doğrulandı. Eklenti tarafındaki tüm kanıtlar birebir okundu.

---

### UX-008 — Başarılı iade sonrası satırın Bakiye sütunu güncellenmiyor; rozet REFUNDED olurken Remaining eski tutarı göstermeye devam ediyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | stale-ui |
| **Konum** | [assets/js/nicepay-admin.js:274](../../../assets/js/nicepay-admin.js#L274) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Başarı dalında yalnızca durum rozeti ve buton güncelleniyor: btn.closest('tr').find('.nicepay-status').removeClass('nicepay-status-paid nicepay-status-partially_refunded').addClass('nicepay-status-refunded').text(adminI18n.statusRefunded || 'REFUNDED'); btn.remove(); (274-278). Aynı satırdaki .nicepay-amount hücresi (admin/class-nicepay-transactions.php:868-879) 'Refunded: %1$s · Remaining: %2$s' değerlerini sunucudan render edilmiş haliyle tutuyor ve dokunulmuyor. ajax_cancel_transaction() yanıtında güncel tutarları döndürmüyor — yalnızca array('message' => ...) (1026).
```

**Başarısızlık senaryosu**

Yönetici 40.000 KRW'lik kalan bakiyeyi iade ediyor. Toast 'Refund completed successfully.' diyor, rozet REFUNDED oluyor, ama hemen yanında 'Remaining: 40,000 KRW' yazmaya devam ediyor. Yönetici iadenin geçtiğinden emin olamıyor; sayfayı yenileyene kadar tabloya güvenemiyor.

**Etki**

İade sonrası aynı ekranda çelişkili iki veri duruyor: rozet REFUNDED, bakiye satırı 'Refunded: 0 KRW · Remaining: 40,000 KRW'. Mali bir ekranda bu, gereksiz ikinci iade denemesine yol açar. Ayrıca i18n.statusRefunded ('REFUNDED') PHP'deki nicepay_get_status_label() 'Refunded' etiketinin ikinci bir çeviri kopyası; CSS zaten text-transform:uppercase uyguladığı için çevirmen gereksiz yere büyük harfli varyant çevirmek zorunda.

**Öneri**

ajax_cancel_transaction() yanıtına güncel değerleri koy: status, status_label (nicepay_get_status_label ile), refunded ve remaining (nicepay_format_amount ile). JS'te rozeti response.data.status_label ve 'nicepay-status-' + response.data.status ile güncelle, .nicepay-amount small içeriğini yeniden yaz. Böylece i18n.statusRefunded anahtarı ve büyük harfli çeviri kopyası tamamen silinebilir.

---

### UX-009 — Ödeme sonuç sayfası başarısızlıkta tekrar deneyebilirsiniz diyor ama tek çıkış yolu Ana Sayfaya Dön — tekrar deneme bağlantısı yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-recovery |
| **Konum** | [includes/class-nicepay-return-handler.php:354](../../../includes/class-nicepay-return-handler.php#L354) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Hata mesajları açıkça yeniden denemeye davet ediyor: 'Payment authentication was not completed. You may try again.' (81), 'Payment approval was not started and the authorization hold was reversed. You may try again.' (109), 'Payment was declined. Please try another payment method.' (225). Ancak render_result_page()'in aksiyon bloğu (354-368) başarısızlıkta tek bir bağlantı basıyor: <a href="<?php echo esc_url( home_url() ); ?>" class="result-btn">Return to Home</a>. 'Open Saved Receipt' ve 'Print Receipt' yalnızca $success iken görünüyor. Ödemenin başlatıldığı sayfaya veya destek iletişimine hiçbir referans yok.
```

**Başarısızlık senaryosu**

Alıcı bir bağış sayfasındaki [nicepay_payment] butonundan ödeme yapıyor, kart reddediliyor. Sonuç sayfası 'Payment was declined. Please try another payment method.' + 'Return to Home' gösteriyor. Alıcı ana sayfaya düşüyor, ödeme sayfasını tekrar bulamıyor ve vazgeçiyor.

**Etki**

Ödemesi reddedilen alıcıya 'başka bir yöntem deneyin' deniyor ama denemesini sağlayacak hiçbir bağlantı verilmiyor; tek seçenek ana sayfaya gitmek. Standalone akışta (WooCommerce sepeti olmadığı için) geri dönülebilecek bir sipariş bağlamı da yok. Dönüşümün doğrudan kaybedildiği nokta.

**Öneri**

render_result_page()'e $retry_url parametresi ekle: standalone'da teklif çözümlemesi sırasında saklanan kaynak sayfa URL'sinden (source_ref / wp_get_referer), WooCommerce'te $order->get_checkout_payment_url() ile türet. Başarısızlıkta birincil buton 'Odemeyi Tekrar Dene' olsun, 'Ana Sayfaya Don' ikincil kalsın. Sayfaya işlem referansını (Moid) ve 'Sorun devam ederse %s referansiyla bize ulasin.' satırını ekle.

---

### UX-010 — Sanal hesap son ödeme tarihi ham sağlayıcı zaman damgası olarak basılıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-presentation |
| **Konum** | [includes/class-nicepay-return-handler.php:325](../../../includes/class-nicepay-return-handler.php#L325) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
326-327: <dt>Deadline</dt><dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>. NICEPAY VbankExpDate alanı YYYYMMDD (veya YYYYMMDDHHMMSS) biçiminde ham bir dizedir; hiçbir biçimlendirme, wp_date()/date_i18n() çağrısı veya saat dilimi açıklaması uygulanmıyor. Aynı blokta tutarlar nicepay_format_amount() ile geçiyor (323), yani bu bilinçli bir seçim değil atlanmış bir adım.
```

**Başarısızlık senaryosu**

Alıcı VBANK ile ödeme başlatıyor, sonuç sayfasında 'Deadline: 20260830235959' görüyor. Ne tarih ne saat olduğunu çıkaramıyor, 'yarın hallederim' diyor, süre doluyor ve işlem expired'a düşüyor.

**Etki**

Alıcı, parayı ne zamana kadar yatırması gerektiğini söyleyen tek alanı '20260830' ya da '20260830235959' gibi okunamaz bir sayı olarak görüyor. Bu, VBANK akışının en kritik bilgisi: kaçırılan son tarih = başarısız ödeme + mağaza için manuel uzlaştırma.

**Öneri**

Ham değeri DateTimeImmutable::createFromFormat('YmdHis' veya 'Ymd', $raw, new DateTimeZone('Asia/Seoul')) ile ayrıştırıp wp_date(get_option('date_format').' '.get_option('time_format').' T', $ts) ile yerelleştir ve eyleme dönük yaz: '30 Agustos 2026, 23:59 (KST) tarihine kadar yatiriniz'. VbankNum için 4'lü gruplama ve yanına Kopyala butonu ekle.

---

### UX-011 — Ödeme sonuç sayfası site kabuğunun tamamen dışında render ediliyor — marka, tema, başlık/altbilgi yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | trust-ux |
| **Konum** | [includes/class-nicepay-return-handler.php:261](../../../includes/class-nicepay-return-handler.php#L261) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
render_result_page() 261-296 arasında kendi <!DOCTYPE html> belgesini üretiyor: wp_head(), get_header(), get_footer(), wp_footer() yok; tema stil sayfası yüklenmiyor; bloginfo('name') veya get_site_icon_url() hiçbir yerde geçmiyor. Tek site referansı 365. satırdaki çıplak home_url() bağlantısı. Stil tamamen inline ve tasarım sistemini elle kopyalıyor (269-294): #2563eb, #dcfce7, #6b7280, #e5e7eb yeniden tanımlanmış; köşe yarıçapları 16/12/10px iken tasarım sisteminin ölçeği 6/10/14px (nicepay.css:27-29).
```

**Başarısızlık senaryosu**

Müşteri özenle tasarlanmış bir Kore mağazasından ödeme yapıyor; onay ekranı olarak logosuz, sistem fontlu, gri zeminli bir kutu görüyor. 'Yanlış bir siteye mi yönlendirildim?' şüphesi doğuyor ve destek hattı aranıyor.

**Etki**

Alıcı ödemeyi tamamladıktan sonra, az önce alışveriş yaptığı siteye hiç benzemeyen, markasız bir beyaz kutuya düşüyor — akışın en kritik güven anında. Ayrıca sonuç sayfası tasarım sisteminin dışında olduğu için marka rengi/font değişiklikleri buraya asla yansımıyor; üçüncü bir stil kaynağı olarak kalıcı bakım yükü yaratıyor.

**Öneri**

Sayfayı site kabuğu içinde render et: locate_template ile temanın ezebileceği bir nicepay/payment-result.php şablonu + get_header()/get_footer(). Mümkün değilse minimum: get_site_icon_url(64) + bloginfo('name') başlığını .result-title'ın üstüne koy ve inline CSS yerine wp_enqueue_style('nicepay-css') ile gerçek stil sayfasını kullan. Ayrıca @media print bloğu ekle — sayfada 'Print Receipt' butonu var (361-363) ama hiç baskı stili yok.

---

### UX-012 — Kontrast: --nicepay-text-muted (#9ca3af, 2.54:1) gerçek bilgilendirme metinlerinde kullanılıyor; hata bildirimi metni 4.41:1'de kalıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | contrast |
| **Konum** | [assets/css/nicepay.css:22](../../../assets/css/nicepay.css#L22) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```css
Hesaplanan oranlar (WCAG 2.1 rölatif parlaklık): #9ca3af beyaz üzerinde 2.54:1. Bu token yalnızca placeholder'da (nicepay.css:328-330) değil şu gerçek metinlerde de kullanılıyor: .nicepay-empty-desc 13px (nicepay-admin.css:212-216), .nicepay-sc-hint 12px (1116-1121), .nicepay-sc-display-mode-text span 12px (689-692), .nicepay-sc-optional (769-777), .nicepay-sc-pv-section-label (1037-1044), .nicepay-method-code (443-447) ve .nicepay-method-icon (nicepay.css:158-165, ikon için 3:1 eşiği de karşılanmıyor). Ayrıca .nicepay-notice-error #dc2626 üzerinde #fef2f2 = 4.41:1 (14px normal → 4.5 gerekli, FAIL); .nicepay-sc-card-meta span #6b7280 üzerinde #f3f4f6 = 4.39:1 (FAIL); .nicepay-sc-output-label #64748b üzerinde #0f172a = 3.75:1 (FAIL).
```

**Başarısızlık senaryosu**

Kısakod üreticisini ilk kez açan mağaza sahibi 'Alici bilgisi bos birakilirsa alici doldurur' açıklamasını (#9ca3af, 12px) fark etmiyor; alanları boş bırakmanın ne yapacağını bilmediği için kendi adını yazıyor ve tüm alıcılar için sabitlenmiş bir form üretiyor.

**Etki**

Hafif görme kaybı olan veya parlak ortamda ekrana bakan kullanıcı için kurulum yönergeleri, boş durum açıklamaları ve ödeme hata mesajları okunamıyor. En kritik olanı hata bildirimi: ödemenin neden başarısız olduğunu anlatan metin eşiğin altında.

**Öneri**

(1) #9ca3af'i yalnızca ::placeholder ve devre dışı buton zemini için sakla; gerçek metinlerde --nicepay-text-secondary (#6b7280, beyazda 4.83:1) kullan. (2) Gri zemin için ayrı token: --nicepay-text-on-muted: #4b5563 (#f3f4f6 üzerinde 6.87:1). (3) .nicepay-notice-error metnini #b91c1c'ye indir (~6.0:1) veya zemini beyaza al. (4) .nicepay-sc-output-label'i #94a3b8'e yükselt (~5.7:1). (5) check-css.js'e token çiftleri için otomatik kontrast doğrulaması ekle.

---

### UX-013 — Kısakod üreticisinde doğrulama sessiz: Name alanı hatası hiçbir metin üretmiyor, #sc-validation paneli hiçbir zaman duyurulmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | form-validation |
| **Konum** | [assets/js/nicepay-shortcode-admin.js:265](../../../assets/js/nicepay-shortcode-admin.js#L265) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Kaydet dalında: if (!name) { $(fields.name).addClass('is-invalid').attr('aria-invalid','true').focus(); return; } (265-268) — hiçbir hata metni DOM'a yazılmıyor, aria-describedby bağlanmıyor ve Name için çeviri anahtarı da yok (admin/class-nicepay-admin.php:848-863'te yalnızca amountRequired ve productNameRequired var). İkinci sorun: <div class="nicepay-sc-validation" id="sc-validation" style="display:none;"> (admin/class-nicepay-admin.php:1102-1105) role/aria-live taşımıyor ve JS onu yalnızca $('#sc-validation').toggle(...) ile gösterip gizliyor (nicepay-shortcode-admin.js:168) — display değişimi hiçbir ekran okuyucuya duyurulmuyor. Panel ayrıca hataların ait olduğu alanlardan uzakta, sağdaki sticky sütunda.
```

**Başarısızlık senaryosu**

Yönetici tutar ve ürün adını doldurup Kaydet'e basıyor. Hiçbir şey olmuyor (Name boş). Butonda geri bildirim yok, toast yok, mesaj yok. Yönetici kaydetmenin bozuk olduğunu düşünüp sayfayı yeniliyor ve tüm girdiyi kaybediyor.

**Etki**

Name'i boş bırakan yönetici Kaydet'e basınca hiçbir mesaj görmüyor; yalnızca kırmızı bir kenarlık beliriyor (nicepay-admin.css:719-722) ve ekran okuyucu sadece 'geçersiz giriş' diyor, nedenini söylemiyor. Amount/Product Name hataları görsel olarak görünüyor ama asla duyurulmuyor.

**Öneri**

(1) Name için nameRequired i18n anahtarı ekle ve hatayı alanın altına standalone formdaki desenle bas: <p class="nicepay-sc-field-error" id="sc-name-error" role="alert"> + input'a aria-describedby. (2) #sc-validation'a role="status" aria-live="polite" ekle ve .toggle() yerine içeriği yazıp temizleyerek çalıştır. (3) Doğrulama mesajlarını alanların yanına taşı; sağ panel yalnızca özet kalsın. (4) Kaydet'te ilk hatalı alana odak ver.

---

### UX-014 — prefers-reduced-motion kapsaması eksik: * seçicisi elemanın kendisini atlıyor ve yönetici stil sayfasında hiç blok yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | reduced-motion |
| **Konum** | [assets/css/nicepay.css:581](../../../assets/css/nicepay.css#L581) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```css
Tek blok nicepay.css:581-589 ve yalnızca torunları hedefliyor: .nicepay-payment-wrapper *, .nicepay-payment-modal-overlay *, .nicepay-loading-overlay *. * birleştiricisi elemanın KENDİSİNİ kapsamaz; oysa .nicepay-payment-modal-overlay { animation: nicepay-modal-bg-in 0.25s } (366) tam da elemanin kendisinde. .nicepay-notice { animation: nicepay-slide-down } (477) sarmalayıcı dışında da render edilebiliyor. En önemlisi grep -n 'prefers-reduced-motion' assets/css/nicepay-admin.css sıfır sonuç veriyor: yönetici tarafındaki 4 keyframe (modal-fade-in, modal-slide-up, toast-in, toast-out), transform:scale(1.15) swatch hover'ı (902-908) ve SONSUZ nicepay-pulse 2s infinite (nicepay.css:544, yalnızca yönetici tablosunda render ediliyor) hiç kısıtlanmıyor.
```

**Başarısızlık senaryosu**

prefers-reduced-motion: reduce ayarlı bir mağaza sahibi işlem defterini açıyor; ekranda 'Waiting for Deposit' durumundaki 8 satırın noktaları 2 saniyelik döngüyle sürekli yanıp sönüyor ve durdurulamıyor. Bir iade yaparken de her toast sağdan kayarak giriyor; kullanıcı baş dönmesi nedeniyle ekranı kullanamaz hale geliyor.

**Etki**

Vestibüler bozukluğu olan mağaza sahibi için işlem tablosundaki waiting rozetleri durmadan nabız atıyor (sonsuz animasyon, WCAG 2.2.2 kapsamı), her toast sağdan kayarak giriyor, her modal yukarı kayıyor — işletim sistemi tercihine rağmen. Ön yüzde de modal arka planı yine solarak açılıyor.

**Öneri**

(1) nicepay.css'teki bloğu elemanların kendisini de kapsayacak şekilde genişlet (.nicepay-payment-wrapper, .nicepay-payment-wrapper *, .nicepay-payment-modal-overlay, ... , .nicepay-notice, .nicepay-status::before). (2) nicepay-admin.css'in sonuna aynı bloğu .nicepay-admin, .nicepay-admin *, .nicepay-toast, .nicepay-toast *, .nicepay-modal-overlay, .nicepay-modal-overlay * kapsamıyla ekle ve animation-iteration-count: 1 !important ile nabız animasyonunu durdur. (3) check-css.js'e 'her @keyframes için bir prefers-reduced-motion karşılığı var mı' kontrolü ekle.

---

### UX-015 — Her iki modal odak tuzağı da son odaklanabilir öğe yükleme sırasında devre dışı kalınca kırılıyor; arka plan içeriği inert değil

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | keyboard-focus |
| **Konum** | [assets/js/nicepay.js:408](../../../assets/js/nicepay.js#L408) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Ön yüz: const focusable = modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]') (nicepay.js:408) — type="hidden" input'ları da yakalıyor (standalone formda 14 tane, templates/standalone-payment-form.php:161-176). setStandaloneLoading() ödeme butonunu disabled yapınca (153-155) last artık odaklanamayan gizli bir input oluyor; document.activeElement === last asla doğru olmadığı için Tab tuzaktan kaçıyor. Yöneticide aynı desen: self.overlay.find('button:not(:disabled), input:not(:disabled), a[href]') ve if (!focusable.length) return; (nicepay-admin.js:174-177) — silme akışında input yokken setLoading(true) her iki butonu da devre dışı bırakıyor ve Tab preventDefault edilmeden arka plana kaçıyor. Her iki modalda da arka plana inert/aria-hidden uygulanmıyor; yalnızca document.body.style.overflow='hidden' var (nicepay.js:401).
```

**Başarısızlık senaryosu**

Yönetici bir kısakodu silmek için modalı açıyor, Sil'e basıyor; setLoading(true) her iki butonu da disabled yapıyor. Yönetici Tab'a basıyor — odak modalın dışına, arkadaki yönetici menüsüne düşüyor ve sonuç geldiğinde nereye döneceğini bilmiyor.

**Etki**

AJAX turu sırasında (tam da kullanıcının bekletildiği anda) klavye odağı modalın dışına kaçıyor. Ekran okuyucu kullanıcısı ise JS ile oluşturulan yönetici modalı açıkken arka plandaki tüm içeriği sanal imleçle gezebiliyor.

**Öneri**

(1) Odaklanabilir seçiciyi gerçek görünürlüğe göre filtrele (el.type !== 'hidden' && el.offsetParent !== null && tabindex !== '-1'). (2) Liste boşaldığında kaçmaya izin verme: event.preventDefault(); dialog.focus(); return; — diyalog kabına tabindex="-1" ver (ön yüzde zaten var, templates/standalone-payment-form.php:84). (3) Yükleme sırasında disabled yerine aria-disabled="true" + tıklama koruması kullan; öğe odaklanabilir kalır ve durum duyurulur. (4) Modal açılırken kardeş içeriğe inert (yoksa aria-hidden) uygula.

---

### UX-016 — Hata bildirimleri zamanlayıcıyla kayboluyor ve bazı mesajlar durumu yanlış anlatıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-messaging |
| **Konum** | [assets/js/nicepay.js:126](../../../assets/js/nicepay.js#L126) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Otomatik kaybolma: nicepay.js:126-128 bildirimi 6000ms sonra fadeOut ile siliyor; showStandaloneError() 8000ms sonra siliyor (186-192); NicePayToast.show() varsayılan 4000ms (nicepay-admin.js:44, 65-68). Hiçbirinde duraklat, kapat butonu veya geri çağırma yok. Yanlış eşleme: nicepay.js:79-85'te window.nicepayStart fonksiyon DEĞİLSE gösterilen mesaj paymentI18n.error || 'Payment system unavailable.' — yani gerçekte gösterilen metin 'Payment error occurred. Please try again.' oluyor (nicepay-payment-gateway.php:511), oysa bu dal PG kütüphanesinin yüklenemediği durum ve tekrar denemek işe yaramaz. Doğru metin olan systemUnavailable anahtarı (519) bu dalda hiç kullanılmıyor. Aynı error anahtarı 75. satırda istisna dalında da kullanılıyor.
```

**Başarısızlık senaryosu**

Bir reklam engelleyici NICEPAY'in pgweb.js dosyasını engelliyor. Kullanıcı Öde'ye basıyor, 'Payment error occurred. Please try again.' çıkıyor ve 6 saniye sonra kayboluyor. Kullanıcı 4 kez daha deniyor; sorunun kendi tarayıcı eklentisi olduğunu asla öğrenemiyor.

**Etki**

Ekran okuyucuyla veya yavaş okuyarak ilerleyen kullanıcı hata metnini bitiremeden mesaj siliniyor ve geri getirilemiyor (WCAG 2.2.1 Timing Adjustable). Ayrıca 'tekrar deneyin' talimatı çözümü olmayan bir arıza için veriliyor.

**Öneri**

(1) Hata bildirimlerinde otomatik kapanmayı kaldır, görünür bir kapat düğmesi ekle (aria-label ile). Bilgi/başarı bildirimleri zamanlayıcıyla kapanabilir. (2) Toast'lara da kapat düğmesi ekle veya hata tipinde süreyi sonsuz yap. (3) nicepay.js:82'yi translated('systemUnavailable', ...) ile değiştir ve 75. satırdaki istisna dalına ayrı bir anahtar ver. (4) 'An unexpected error occurred.' gibi çıkmaz metinleri her zaman bir sonraki adımı söyleyen metinlerle değiştir.

---

### UX-017 — Kullanıcıya görünen çevrilmemiş dizeler: Copy failed, Error, title=Blue, placeholder=Pay Now, 0 KRW

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | i18n-ux |
| **Konum** | [assets/js/nicepay-admin.js:305](../../../assets/js/nicepay-admin.js#L305) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
nicepay-admin.js 305, 311, 364, 375. satırlarda 'Copy failed' doğrudan gömülü — copyFailed çevirisi var ama yalnızca nicepayShortcodeAdmin.i18n'e verilmiş (admin/class-nicepay-admin.php:856), nicepayAdmin.i18n'e (78-95) verilmemiş. 341. satırda resp.data.message || 'Error'. admin/class-nicepay-admin.php:1016-1021'de altı renk düğmesinin tek erişilebilir adı çevrilmemiş title="Blue"/"Black"/"Green"/"Red"/"Purple"/"Orange". 998. satırda placeholder="Pay Now" (çevirisi 850. satırda payNow olarak zaten var). 1055. satırda <div id="sc-pv-amount">0 KRW</div> sabit.
```

**Başarısızlık senaryosu**

Korece yönetici panelinde çalışan bir mağaza sahibi kısakod kartındaki Kopyala'ya basıyor, pano API'si başarısız oluyor ve tamamen Korece bir arayüzde 'Copy failed' toast'ı çıkıyor. Aynı sayfada renk düğmelerini klavyeyle gezerken hiçbir ad duymuyor.

**Etki**

Korece/Türkçe/Çince arayüzde İngilizce parçalar beliriyor. Özellikle renk düğmelerinin tek erişilebilir adı çevrilmemiş title niteliği: dokunmatik cihazlarda hiç görünmüyor ve birçok ekran okuyucu ayarında okunmuyor — yani bu düğmelerin pratikte erişilebilir adı yok.

**Öneri**

(1) nicepayAdmin.i18n'e copyFailed ve genericError anahtarlarını ekle, gömülü dizeleri bunlarla değiştir. (2) Renk düğmelerini çevrilebilir aria-label ile etiketle ve seçili durumu duyur (aria-pressed); JS'te is-active ile birlikte aria-pressed'i de güncelle (nicepay-shortcode-admin.js:217-229). (3) placeholder="Pay Now" ve '0 KRW' başlangıç değerlerini PHP tarafında çevrilmiş / nicepay_format_amount ile üretilmiş değerlerle doldur.

---

### UX-018 — Kısakod üreticisindeki iki radyo grubunun grup semantiği yok; gizli radyolarda :focus-within odak stili tanımlı değil

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | accessibility-semantics |
| **Konum** | [admin/class-nicepay-admin.php:888](../../../admin/class-nicepay-admin.php#L888) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
'Display Mode' radyo grubu (888-909) ve 'Payment Method' çip grubu (947-961) düz <div> içinde; ne <fieldset>/<legend> ne role="radiogroup" + aria-labelledby var. Grubu tanımlayan <h3 class="nicepay-sc-section-title"> (884-887, 943-946) kontrollere bağlanmamış. Radyolar görsel olarak gizli: .nicepay-sc-display-mode input { position:absolute; opacity:0; width:0; height:0; } (nicepay-admin.css:642-647) ve .nicepay-sc-method-chip input aynı (935-940). Ön yüzde bu desen :focus-within odak halkasıyla dengeleniyor (nicepay.css:104-107) ancak grep -n 'focus-within' assets/css/nicepay-admin.css sıfır sonuç veriyor. Etiketlere position: relative de verilmemiş.
```

**Başarısızlık senaryosu**

Klavyeyle çalışan bir yönetici Tab ile Display Mode grubuna geliyor. Ekranda hiçbir görsel değişiklik olmuyor — hangi seçenekte olduğunu bilmiyor. Ok tuşuna basıyor, seçim sessizce değişiyor ve sayfa mutlak konumlu radyo nedeniyle beklenmedik bir yere kayabiliyor.

**Etki**

Ekran okuyucu 'Inline, radyo düğmesi, 1/2' derken hangi grubun içinde olduğunu söylemiyor. Klavye kullanıcısı seçenekler arasında gezerken hangi seçeneğin odakta olduğunu göremiyor: odak göstergesi görsel olarak gizlenmiş radyonun kendisinde ve etikette hiçbir odak stili yok (WCAG 2.4.7 ihlali).

**Öneri**

(1) Her iki grubu <fieldset> + <legend> ile sar (h3'ü legend'e dönüştür, fieldset varsayılan stillerini sıfırla). (2) nicepay-admin.css'e ön yüzdekiyle aynı kuralı ekle: .nicepay-sc-display-mode:focus-within, .nicepay-sc-method-chip:focus-within { outline: 2px solid var(--nicepay-primary); outline-offset: 2px; }. (3) Her iki etikete position: relative ekle. (4) Aynı kontrolü .nicepay-method-checkbox (420-428) için de yap.

---

### UX-020 — RTL, prefers-color-scheme ve forced-colors desteği tamamen yok; sabit palet WP yönetici renk şemalarını yok sayıyor; odak halkaları outline:none + düşük alfa gölgeye dayanıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | theming |
| **Konum** | [assets/css/nicepay-admin.css:76](../../../assets/css/nicepay-admin.css#L76) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```css
grep -rni 'rtl|margin-inline|padding-inline|inset-inline|prefers-color-scheme|forced-colors' assets/ includes/ admin/ nicepay-payment-gateway.php sıfır sonuç veriyor. Yönlü boşluklar her yerde fiziksel: margin-right (nicepay.css:141, 161), right:12px (381-384), right:20px (nicepay-admin.css:349), margin-left:auto (446, 776), border-left (612). wp_style_add_data($handle,'rtl','replace') hiçbir yerde çağrılmıyor ve rtl.css yok. Sabit renkler --wp-admin-theme-color yerine kendi #2563eb'sini kullanıyor (nicepay-admin.css:657, 961, 163). Odak stilleri: .nicepay-field-input:focus { outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); } (nicepay.css:322-326); aynı desen nicepay-admin.css:76-81, 286-291, 811-815, 842-846'da tekrarlanıyor. box-shadow forced-colors modunda tamamen düşürülür, outline:none ise kalıcıdır.
```

**Başarısızlık senaryosu**

Windows Yüksek Kontrast modu kullanan bir alıcı standalone formda Tab ile e-posta alanına geliyor. outline:none nedeniyle hiçbir odak göstergesi çizilmiyor (box-shadow zorlanmış renklerde yok sayılıyor) ve kenarlık rengi değişimi de sistem paletiyle geçersiz kılınıyor. Kullanıcı hangi alanda olduğunu göremiyor.

**Etki**

(1) Arapça/İbranice bir site için düzen mekanik olarak bozulur. (2) Windows Yüksek Kontrast / forced-colors modunda metin girişlerinin GÖRÜNÜR ODAK GÖSTERGESİ HİÇ KALMAZ — WCAG 2.4.7 ihlali. (3) 9 WP yönetici renk şemasından 8'inde eklentinin mavisi çevredeki krom ile çakışıyor. (4) Koyu tema tercihi olan ziyaretçi için ödeme kartı sabit beyaz kalıyor.

**Öneri**

(1) Tüm outline:none kullanımlarını kaldır; odak için outline: 2px solid var(--nicepay-primary); outline-offset: 2px; kullan ve @media (forced-colors: active) { :focus-visible { outline: 2px solid Highlight; } } bloğu ekle. (2) Fiziksel özellikleri mantıksal olanlara çevir (margin-inline-start/end, inset-inline-start/end, border-inline-start) — bu tek başına RTL'i çözer. (3) Yöneticide sabit mavileri var(--wp-admin-theme-color, #2271b1) ile değiştir. (4) :root token'larının yanına @media (prefers-color-scheme: dark) bloğu ekleyip yalnızca token'ları ez; geri kalan kurallar zaten token kullandığı için otomatik uyum sağlar.

---

### UX-021 — Tutar biçimlendirmesi yerelleştirilmemiş ve önizleme ile gerçek çıktı birbirini tutmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | i18n-ux |
| **Konum** | [includes/nicepay-functions.php:1269](../../../includes/nicepay-functions.php#L1269) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
nicepay_format_amount() (1269-1279) number_format() kullanıyor — WordPress'in yerel-duyarlı number_format_i18n()'ini değil ve wc_price()'ı hiç kullanmıyor; sonuç her zaman İngilizce ayraçlı ve para birimi son ekli ('10,000 KRW'). Buna karşılık kısakod üreticisinin canlı önizlemesi tarayıcı yereline göre biçimlendiriyor: numericAmount.toLocaleString() (assets/js/nicepay-shortcode-admin.js:141). Bu fonksiyonun çıktısı yönetici tablosunda (admin/class-nicepay-transactions.php:740-742, 869-876), sipariş özetinde (admin/class-nicepay-admin.php:1175-1179), standalone formda (templates/standalone-payment-form.php:100) ve sonuç sayfasında (includes/class-nicepay-return-handler.php:323, 344) kullanılıyor.
```

**Başarısızlık senaryosu**

Almanca yerelli tarayıcı kullanan mağaza sahibi 10000 yazıyor; canlı önizleme '10.000 KRW' gösteriyor. Kaydediyor, ürün sayfasını açıyor ve orada '10,000 KRW' görüyor. Hangisinin doğru olduğundan emin olamıyor ve tutarı silip yeniden giriyor.

**Etki**

(1) Tutarlar WooCommerce'in aynı ekranlardaki kendi biçimlendirmesinden farklı görünüyor — yönetici sipariş ekranında iki farklı para gösterimi yan yana duruyor. (2) Önizleme ile gerçek çıktı farklı olabiliyor: önizleme yalan söylüyor.

**Öneri**

(1) nicepay_format_amount() içinde number_format_i18n() kullan ve WooCommerce yüklüyse wc_price($amount, array('currency'=>$currency)) ile aynı biçimi üret (yönetici tabloları için wp_strip_all_tags uygula). (2) Önizlemeyi sunucu biçimiyle hizala: nicepayShortcodeAdmin'e amountFormat (binlik/ondalık ayraç + para birimi konumu) gönder ve toLocaleString() yerine bunu kullan.

---

### UX-022 — PR ile gelen docs/analysis/05-user-experience.md kodun mevcut haliyle çelişiyor ve satır referansları geçersiz

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | doc-code-mismatch |
| **Konum** | [docs/analysis/05-user-experience.md:1534](../../../docs/analysis/05-user-experience.md#L1534) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
Doküman aynı PR'da düzeltilmiş şeyleri hâlâ açık bulgu olarak anlatıyor: satır 1534 'assets/css/nicepay.css contains exactly one @media rule — @media (max-width: 640px) — and no prefers-reduced-motion block' diyor, oysa nicepay.css:581'de o blok var. Satır 1586 'the pay button forces color: #fff !important twice' diyor, oysa nicepay.css:213 ve 228 artık color: var(--nicepay-button-text, #fff) !important kullanıyor. Satır referansları kaymış: .nicepay-notice-error için 'nicepay.css:444-459' deniyor (gerçek 480-484), pulse için 'nicepay.css:519' (gerçek 544), 'templates/standalone-payment-form.php:316' (dosya toplam 194 satır). Satır 1553'te de 'grep ... prefers-reduced-motion ... returns nothing' iddiası var.
```

**Başarısızlık senaryosu**

Yeni bir katkıcı UX-097'yi düzeltmek için görev alıyor, dokümanın dediği gibi nicepay.css'e prefers-reduced-motion eklemeye çalışıyor ve bloğun zaten var olduğunu görüyor; hangi bulguların geçerli olduğunu anlamak için 1774 satırlık dokümanı elle kodla karşılaştırmak zorunda kalıyor.

**Etki**

PR'ın ~14.700 satırı bu analiz dokümanları ve depoya kalıcı giriyor; gelecekte 'mevcut durum' referansı olarak okunacak. Yanlış satır numaraları ve düzeltilmiş bulguların açık görünmesi, bir sonraki geliştiricinin var olmayan sorunları aramasına veya gerçekten açık kalanları (RTL, admin reduced-motion, 4.41:1 kontrast) çözülmüş sanmasına yol açar.

**Öneri**

Dokümanları ya PR'dan çıkar (ayrı dal/wiki), ya da her bulguya durum alanı ekleyip PR içinde güncelle: 'Status: FIXED in this PR (commit e6975d9)' / 'Status: OPEN'. Satır referanslarını dosya+sembol adına çevir (ör. 'nicepay.css > .nicepay-notice-error'). Minimum: 00-executive-summary.md'ye 'Bu analiz X tarihli koda aittir; PR #3 şu maddeleri kapatmıştır' listesi ekle.

---

### UX-019 — Ölü önizleme butonları klavye sırasında: pointer-events:none ile tıklanamaz ama Tab ile odaklanılabiliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | keyboard-focus |
| **Konum** | [admin/class-nicepay-admin.php:778](../../../admin/class-nicepay-admin.php#L778) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Kısakod kartlarındaki önizleme butonu: <button type="button" class="nicepay-pay-button" style="...;pointer-events:none;..."> (778-780) — disabled veya tabindex="-1" yok. Canlı önizleme butonu: <button type="button" class="nicepay-pay-button" id="sc-preview-btn"> (1081-1083); CSS ona da pointer-events:none veriyor (nicepay-admin.css:1108-1114) ama yine odaklanabilir. Ayrıca .nicepay-sc-preview-area .nicepay-pay-button kuralı (1001-1010) DOM'da hiç render edilmeyen bir sarmalayıcıyı hedefliyor — ölü seçici.
```

**Başarısızlık senaryosu**

Yönetici 6 kayıtlı kısakodu olan listede klavyeyle Düzenle bağlantısına ulaşmak istiyor; her kartta önce işlevsiz bir Pay Now butonuna odaklanıyor ve Enter'a basınca hiçbir tepki almıyor — kontrolün bozuk olduğunu düşünüyor.

**Etki**

Klavye kullanıcısı kısakod listesinde N kart için N adet işlevsiz 'Pay Now' butonuna takılıyor; her biri odak alıyor, Enter'a basınca hiçbir şey olmuyor. Üretici sayfasında da form ile Kaydet arasında ölü bir tab durağı var. Ekran okuyucu bunları gerçek buton olarak duyuruyor.

**Öneri**

Her iki önizleme öğesini etkileşimli olmayan hale getir: tabindex="-1" aria-hidden="true" disabled ekle veya tamamen <span class="nicepay-pay-button nicepay-pay-button--preview" aria-hidden="true"> olarak render et (semantik olarak daha doğru: bunlar buton değil görsel örnek). Ölü .nicepay-sc-preview-area kural bloğunu (nicepay-admin.css:1001-1010) sil.

---

### UX-023 — check-css.js yalnızca sözdizimi ayrıştırması yapıyor — kontrast, ölü seçici, eksik durum ve stil bütünlüğü için hiçbir kapı yok

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | tooling |
| **Konum** | [.github/scripts/check-css.js:34](../../../.github/scripts/check-css.js#L34) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Betiğin tüm doğrulaması csstree.parse(file.source, ...) (34-42) — dosya geçerli CSS olduğu sürece geçiyor. Dosya adı allowlist'i (9-19) dışında hiçbir içerik kontrolü yok. Bu yüzden UX-001 (eksik 5 durum rozeti), UX-002 (.nicepay-notice arka planı yok), UX-003 (.nicepay-error hiç tanımlı değil), UX-012 (kontrast ihlalleri), UX-014 (eksik reduced-motion), ölü seçiciler (nicepay-admin.css:1001-1010) ve ölü token'lar (--nicepay-success-light, --nicepay-warning, --nicepay-warning-light: yalnızca tanım, sıfır kullanım) CI'dan sorunsuz geçti.
```

**Başarısızlık senaryosu**

Bir sonraki sürümde defter'e yeni bir durum (ör. chargeback) ekleniyor. PHP tarafı nicepay-status-chargeback sınıfını basıyor, CSS'e karşılığı eklenmiyor, check-css.js yeşil yanıyor ve yeni durum yönetici tablosunda görünmez bir etiket olarak çıkıyor — UX-001 birebir tekrarlanıyor.

**Etki**

CSS bu üründe kullanıcıya görünen durumların (durum rozetleri, uyarı bantları, hata kutuları) tek kaynağı; ama CI onu yalnızca 'ayrıştırılabilir mi' diye kontrol ediyor. Sonuç: bu raporun en önemli bulguları otomatik yakalanabilecekken üretime kadar geldi.

**Öneri**

(1) stylelint ekle (stylelint-config-standard + stylelint-a11y). (2) check-css.js'e üç özel doğrulama koy: (a) nicepay_get_status_label() anahtarlarını ve nicepay-notice-* varyantlarını PHP'den okuyup CSS'te karşılık kuralının varlığını doğrula; (b) her @keyframes için bir prefers-reduced-motion karşılığı; (c) token çiftleri için WCAG kontrast hesabı (~20 satır). (3) Tanımlanıp hiç kullanılmayan --nicepay-* token'larını hata olarak işaretle.

---

### UX-024 — Dokunma hedefleri 44px altında ve mobil modalda kapatma düğmesi ürün adı başlığıyla çakışıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | touch-target |
| **Konum** | [assets/css/nicepay.css:381](../../../assets/css/nicepay.css#L381) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```css
Ölçüler: .nicepay-payment-modal-close 32x32px (nicepay.css:381-398); .nicepay-copy-btn padding:2px + 14x14 SVG = ~18x18px (nicepay-admin.css:147-170); .nicepay-sc-color-swatch 24x24px (891-900); mobilde .nicepay-cancel-link padding:8px 0 + 0.875rem = ~33px (nicepay.css:575-578). Çakışma geometrisi: 360px ekranda overlay padding 16px → modal 328px; kapatma düğmesi modalın sağından 12px-44px arasını kaplıyor; içerik sütunu ise modal-body padding 8px + wrapper padding 16px = sağdan 24px'te bitiyor (nicepay.css:406, 555-559) → düğme içerik sütununun üzerine 20px biniyor. .nicepay-payment-info h3 (templates/standalone-payment-form.php:98, goods_name 40 bayta kadar) hiçbir padding-right almıyor.
```

**Başarısızlık senaryosu**

Alıcı telefondan modal modundaki bir kısakodu açıyor; ürün adı 'Premium Yillik Uyelik Paketi' iki satıra sarıyor ve ilk satırın son kelimeleri × düğmesinin altında kalıyor. Alıcı başlığı okumak için oraya dokunuyor ve modal kapanıyor.

**Etki**

WCAG 2.2 SC 2.5.8'in 24x24 asgarisini kopyalama düğmesi karşılamıyor (18x18); diğerleri asgariyi geçse de mobil dokunma konforu için önerilen 44x44'ün altında. Çakışma ise gerçek bir okunabilirlik hatası.

**Öneri**

(1) .nicepay-payment-modal-body .nicepay-payment-info h3 { padding-inline-end: 44px; } ekle. (2) Kapatma düğmesini 44x44'e çıkar (1) ile birlikte. (3) .nicepay-copy-btn'e min-width/min-height:24px; padding:5px ver. (4) Mobilde iptal bağlantısını min-height:44px; display:inline-flex; align-items:center yap. (5) 'Cancel' metnini netleştir: ödeme butonunun yanında 'siparişimi iptal et' gibi okunuyor; 'Ödeme yöntemini değiştir' veya 'Odeme sayfasina don' daha doğru.

---

### UX-025 — Yapılandırma uyarıları her yönetici sayfasında, kapatılamaz biçimde gösteriliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | admin-noise |
| **Konum** | [nicepay-payment-gateway.php:257](../../../nicepay-payment-gateway.php#L257) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
configuration_notice() admin_notices kancasına bağlı (126) ve hiçbir ekran kısıtı yok; her uyarı '<div class="notice ' . esc_attr( $class ) . '">' olarak basılıyor (265) — is-dismissible sınıfı yok, kapatma durumu hiçbir yerde saklanmıyor. nicepay_get_configuration_warnings() (includes/nicepay-functions.php:494-532) test modu + ödeme yüzeyi açıkken kalıcı bir 'warning' üretiyor (507-513).
```

**Başarısızlık senaryosu**

Mağaza sahibi 3 hafta test modunda çalışıyor. Her Yazılar, Ürünler, Ayarlar sayfasında aynı sarı bandı görüyor ve görmezden gelmeye başlıyor. Canlıya geçtiğinde merchant key eksik kalıyor; kırmızı 'live credentials missing' bandı aynı yerde çıkıyor ve fark edilmiyor.

**Etki**

Geliştirme aşamasındaki bir mağaza haftalarca test modunda kalabilir; bu süre boyunca wp-admin'in HER sayfasında kapatılamayan bir bant duruyor. Uyarı körlüğü yaratıyor: gerçekten kritik 'live credentials missing' hatası da aynı gürültünün içinde kayboluyor.

**Öneri**

(1) 'warning' tipine is-dismissible ekle ve kapatmayı uyarı koduna bağlı kullanıcı meta'sında sakla; 'error' tipini kapatılamaz bırak. (2) Uyarıları get_current_screen() ile WooCommerce, Eklentiler ve NicePay ekranlarıyla sınırla. (3) Test modu uyarısını yönetici çubuğunda kalıcı küçük bir rozete indir (WP'nin 'Arama motorlarını engelle' deseni gibi).

---

### UX-026 — Blocks checkout adaptörünün ürettiği sınıfların hiç CSS'i yok ve ödeme yöntemi ikonu gösterilmiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | css-missing-state |
| **Konum** | [assets/js/nicepay-blocks.js:29](../../../assets/js/nicepay-blocks.js#L29) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Adaptör üç sınıf üretiyor: nicepay-blocks-label (16), nicepay-blocks-content (34) ve test modu için nicepay-blocks-test-mode (29). grep -rn 'nicepay-blocks' assets/css/ sıfır sonuç veriyor — hiçbiri stillenmiyor; test modu göstergesi yalnızca <strong> kalınlığından ibaret. Ayrıca ikon yok: nicepay_get_method_icon() mevcut (includes/nicepay-icons.php:19) ama Blocks Label bileşeninde kullanılmıyor ve includes/class-nicepay-gateway.php'de $this->icon ataması/get_icon() override'ı yok. Blocks checkout'ta nicepay.css'in yüklendiğine dair garanti de yok: enqueue_scripts() yalnızca is_payment_page() veya is_checkout_pay_page() için yüklüyor (nicepay-payment-gateway.php:330-340).
```

**Başarısızlık senaryosu**

Blocks checkout kullanan bir mağazada ödeme listesinde logolu seçeneklerin altında logosuz, sade 'NicePay Payment' satırı görünüyor; test modundayken de yalnızca kalın bir cümle ekleniyor ve alıcı bunu uyarı olarak algılamıyor.

**Etki**

Blocks checkout'unda NicePay satırı, diğer ödeme yöntemlerinin logolu satırlarının yanında yalnızca düz metin olarak görünüyor — güven ve tanınırlık açısından zayıf. Test modu göstergesi de UX-002'deki akıbete uğruyor.

**Öneri**

(1) nicepay.css'e Blocks sınıfları için stil ekle (test modu için UX-002'de önerilen uyarı varyantını kullan) ve NicePay_Blocks_Integration üzerinden Blocks checkout'ta stilin yüklendiğinden emin ol. (2) Klasik gateway'e $this->icon ata veya get_icon() override et; Blocks Label bileşenine aynı SVG'yi settings.icon_html üzerinden wp.element.RawHTML ile geçir.

---

### UX-027 — Filtre etiketleri placeholder metniyle aynı ve tarih sütunu yerelleştirilmemiş biçimde gösteriliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | copy-quality |
| **Konum** | [admin/class-nicepay-transactions.php:660](../../../admin/class-nicepay-transactions.php#L660) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
screen-reader-text etiketleri kontrolün ne olduğunu değil varsayılan değerini tekrarlıyor: 'All Statuses' (660), 'All Methods' (670), 'From' (680), 'To' (682), 'Search...' (684) — sonuncusu üç noktayla birlikte erişilebilir ad oluyor. Tarih sütunu get_date_from_gmt( $item->created_at, 'Y-m-d H:i:s' ) (793-795) ile sabit biçimde basılıyor; aynı eklentinin başka yerinde wp_date() kullanılıyor (admin/class-nicepay-admin.php:578). Sayfalama bloğunda (948-963) WP list-table'ların standart screen-reader-text başlığı ve tablenav'a aria-label yok.
```

**Başarısızlık senaryosu**

Ekran okuyucu kullanan bir yönetici filtre çubuğunda geziniyor; kontrollerin adları sırasıyla 'All Statuses', 'All Methods', 'From', 'To', 'Search dot dot dot' olarak okunuyor. Hangi tarihin başlangıç hangisinin bitiş olduğu ve neyin arandığı anlaşılmıyor.

**Etki**

Ekran okuyucu 'All Statuses, açılır kutu' diyor — kullanıcı bunun bir durum filtresi mi olduğunu anlamıyor; 'Search dot dot dot' ise doğrudan yanlış bir ad. Tarihler her yerelde ISO biçiminde ve saat dilimi belirtilmeden gösteriliyor, oysa site yöneticisinin ayarladığı biçim ve saat dilimi mevcut.

**Öneri**

(1) Etiketleri amaca göre yaz: 'Islem durumuna gore filtrele', 'Odeme yontemine gore filtrele', 'Baslangic tarihi', 'Bitis tarihi', 'Islemlerde ara (TID, Moid veya siparis numarasi)'. (2) Tarihi wp_date(get_option('date_format').' '.get_option('time_format'), $ts) ile yerelleştir ve sütun başlığına saat dilimi bilgisi ekle. (3) Sayfalamayı <nav class="tablenav bottom" aria-label="..."> içine al ve toplam sonuç sayısını <span class="displaying-num"> ile göster.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenenler (tamamı baştan sona okundu): templates/payment-form.php (77 satır), templates/standalone-payment-form.php (194), assets/css/nicepay.css (590), assets/css/nicepay-admin.css (1273), assets/js/nicepay.js (499), assets/js/nicepay-admin.js (380), assets/js/nicepay-shortcode-admin.js (312), assets/js/nicepay-blocks.js (51), admin/class-nicepay-admin.php (1199, ürettiği tüm HTML), admin/class-nicepay-transactions.php render() + ajax_cancel_transaction (560-1030), includes/class-nicepay-return-handler.php render_result_page (251-374), includes/nicepay-icons.php, .github/scripts/check-css.js, nicepay-payment-gateway.php'nin UI bölümleri (kısakod render, enqueue, configuration_notice, plugin_action_links) ve nicepay_format_amount / nicepay_get_contrast_color / nicepay_get_status_label / nicepay_get_configuration_warnings. docs/analysis/05-user-experience.md hedefli grep ile kodla karşılaştırıldı. Kontrast oranları WCAG 2.1 rölatif parlaklık formülüyle elle hesaplandı (#9ca3af/beyaz 2.54:1; #dc2626/#fef2f2 4.41:1; #6b7280/#f3f4f6 4.39:1; #64748b/#0f172a 3.75:1; geçenler: #6b7280/beyaz 4.83:1, #854d0e/#fef9c3 6.38:1, #4b5563/#f3f4f6 6.87:1, #94a3b8/#1e293b 5.71:1, #2563eb üzerinde beyaz 5.17:1). Ek not: nicepay_get_contrast_color() en kötü durumda 4.19:1 döndürebiliyor (ör. buton rengi #808080 -> 4.44:1) ve üretici arayüzünde hiçbir kontrast uyarısı yok — bu düşük etkili olduğu için ayrı bulgu yapılmadı, UX-012 kapsamında değerlendirilmeli. İNCELENEMEYENLER: (1) Gerçek tarayıcıda render ve otomatik erişilebilirlik denetimi (axe/Lighthouse) yapılamadı; düzen çakışması ve mobil davranış iddiaları CSS geometrisinden hesaplandı, cihazda doğrulanmadı. (2) UX-006'daki WordPress çekirdeği list-tables.css mobil kuralları depoda bulunmadığı için doğrudan okunamadı; bulgu, tablonun çekirdek sözleşmesinin (column-primary/data-colname/toggle-row) tamamen eksik olması kanıtına dayanıyor, bu nedenle confidence: medium. (3) İşlem tablosunun 1-560 satır aralığı (sorgu/CSV dışa aktarım mantığı) bu boyutun kapsamı dışında olduğu için yalnızca tarandı. (4) Ekran okuyucu davranışı (NVDA/VoiceOver) fiilen test edilmedi; ARIA iddiaları spesifikasyon davranışına dayanıyor. (5) languages/*.po çeviri kapsamı i18n boyutuna ait olduğu için yalnızca eksik/gömülü dize tespiti düzeyinde ele alındı.

**Açık sorular**

- UX-006: WordPress çekirdeğinin 782px altı list-table kuralları altında bu tablo tamamen mi gizleniyor yoksa etiketsiz yığılmış hücreler olarak mı çıkıyor? Gerçek bir WP kurulumunda 360px genişlikte ekran görüntüsüyle kesinleştirilmeli — düzeltme her iki durumda aynı, ancak önem derecesi değişir.
- İade butonunun her zaman kalan bakiyenin tamamını iade etmesi bilinçli bir ürün kararı mı (kısmi iade yalnızca WooCommerce sipariş ekranından) yoksa eksik bir özellik mi? Bilinçliyse diyalogda açıkça yazılmalı.
- Ödeme sonuç sayfasının site kabuğunun dışında tutulması bilinçli bir izolasyon kararı mı (tema JS'inin ödeme dönüşüne karışmaması için)? Öyleyse en azından site adı ve logosu enjekte edilmeli.
- prefers-color-scheme: dark desteği yol haritasında var mı? Token tabanlı yapı sayesinde maliyeti ~15 satır.
- docs/analysis/* dosyaları depoda kalıcı tutulacak mı? Tutulacaksa her bulguya FIXED/OPEN durumu eklenmeli; tutulmayacaksa PR'dan çıkarılmalı.
- nicepay_get_contrast_color() yalnızca iki aday arasından daha iyisini seçiyor ve 4.5:1 garanti etmiyor (en kötü durum ~4.19:1). Üretici arabirimine bir kontrast uyarısı eklenmesi planlanıyor mu?

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 27 |

## Öncelikli aksiyon listesi

1. **UX-004** — Alıcı alanlarını form içine taşı (gizli input bloğunun üstüne) ve fieldset + legend ile grupla. Taşımak mümkün değilse her görünür input'a form="<?php echo esc_attr( $form_id ); ?>" ekle. Her iki durumda da butonu type="submit" yapıp form üzerinde submit olayını dinle (preventDefault + startStandalone), böylece Enter doğal çalışsın. Doğrulamayı JS'te tutmaya devam edeceksen novalidate ekle ve requ
2. **UX-007** — (1) Butonu data-amount ve data-currency ile donat; modal mesajını sprintf ile kur: 'Bu islemden %s iade edilecek (Siparis #%s, TID %s). Bu islem geri alinamaz.' (2) Terminolojiyi tek kelimede sabitle — görünen her yerde 'iade' kullan, aria-label'ı da buna çevir. (3) Diyaloğa isteğe bağlı tutar alanı ekle (varsayılan ve max = kalan bakiye; CELLPHONE ve kısmi iadeye kapalı kart durumlarında salt-oku
