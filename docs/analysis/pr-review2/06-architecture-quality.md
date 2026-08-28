# 06 — Mimari ve Kod Kalitesi

> PR #3 sistematik review · düşmanca doğrulamalı · 21 bulgu

## Özet

Bu PR, güvenlik ve para doğruluğu açısından disiplinli bir kod tabanı üretmiş: tüm karşılaştırmalar katı (`===`, `in_array(..., true)`), hiç `@` bastırma veya boş `catch` yok, PHP 8.0+ sözdizimi kullanılmadığı için 7.4 uyumu gerçekten sağlanmış, para aritmetiği tamsayı-string üzerinden yürüyor. Ancak yapısal olarak zorlanıyor: `includes/nicepay-functions.php` 59 global fonksiyonla 1956 satırlık bir "god file" ve tek başına repository + imza + para + doğrulama + i18n etiket katmanlarını barındırıyor; WooCommerce ve standalone dönüş işleyicileri ~300 satırlık neredeyse birebir kopya; `render()` 434, `handle_return()` 314, `process_refund()` 225 satır. Ürünün "birinci sınıf" olma iddiasını en çok zedeleyen şey ölü ağırlık ve tek-kaynak eksikliği: tamamen erişilemez bir VBANK alt sistemi (dal + sütun + indeks + ayar + UI), hiçbir yerde okunmayan `config_fingerprint`/`binding_token_hash` sütunları, altı ayrı yerde yeniden yazılmış "hazırlık kontrolü", üç ayrı redaksiyon implementasyonu, iki ayrı işlem-listesi sorgu yolu. Genişletilebilirlik pratikte sıfır: kod tabanında hiç `do_action()` yok, yalnızca 7 filtre var; bir ödeme ağ geçidi için ERP/muhasebe entegrasyonlarının tutunacağı hiçbir yaşam döngüsü olayı bulunmuyor. Son olarak WPCS/PHPStan gibi hiçbir statik analiz aracı yok — `composer lint:php` sadece `php -l`; bu yüzden karışık girinti (6 dosya tab, 10 dosya boşluk, `.editorconfig` boşluk diyor), karışık Yoda kullanımı ve `__( $degisken )` gibi i18n ihlalleri CI'dan geçebiliyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 16 |
| 🔵 Düşük | 3 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 1 |
| Bağımsız doğrulama kararı alan bulgu | 3 / 21 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 10 |

## Güçlü yönler

- Tip güvenliği örnek düzeyde: kapsam içindeki hiçbir PHP dosyasında gevşek `==`/`!=` yok ve tüm `in_array()` çağrıları üçüncü parametreyle katı (grep ile doğrulandı; tek `==` eşleşmesi base64 anahtarındaki dolgu karakteri).
- PHP 7.4 uyumu iddia edilen değil gerçek: `str_contains`, `?->`, `match(`, `enum`, `readonly`, `never`, ctor promotion, first-class callable, `array_is_list` — hiçbiri kullanılmamış. Aynı şekilde 8.x'te kaldırılan `create_function`, `each()`, `${var}` interpolasyonu da yok.
- Hata yutma yok: kod tabanında tek bir `@` bastırma operatörü ve tek bir boş `catch` bloğu bulunmuyor; her `catch` en az `nicepay_log(..., 'error')` çağırıyor.
- `DateTimeImmutable::getLastErrors()` dönüşünün PHP 8.2'de `false` olabilmesi `is_array( $errors )` ile doğru şekilde ele alınmış (includes/class-nicepay-inbound-validator.php:277-281) — kolayca kaçırılabilecek bir 8.2 davranış değişikliği.
- `NicePay_Transaction_Schema` sütun/format tanımını tek kaynakta topluyor ve para sütunlarını `%s` formatıyla yazarak float dönüşümünü engelliyor (includes/class-nicepay-transaction-schema.php:232-247).
- Yan etkisiz doğrulama katmanı ayrıştırılmış: `NicePay_Inbound_Validator` hiçbir yazma yapmıyor ve bunu docblock'ta açıkça sözleşme olarak belirtiyor (satır 22-24) — temiz bir sorumluluk ayrımı.
- SSRF allowlist'i host + path + şema + port düzeyinde kapalı uçlu (includes/class-nicepay-api.php:178-200) ve `request_cancel` sabit URL'i bile aynı doğrulamadan geçiriyor (satır 596).
- Fonksiyon başına docblock kapsamı yüksek; özellikle `nicepay_get_mismatched_approval_audit` (includes/nicepay-functions.php:189-201) gibi yerlerde *neden* fail-closed davranıldığı açıklanmış — bakım için değerli.

## Bulgular

### ARCH-002 — WooCommerce ve standalone dönüş işleyicileri ~300 satır neredeyse birebir kopya

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | duplication |
| **Konum** | [includes/class-nicepay-gateway.php:376](../../../includes/class-nicepay-gateway.php#L376) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
`WC_Gateway_NicePay::handle_return()` (376-689) ve `NicePay_Return_Handler::process()` (25-227) aynı akışı iki kez yazıyor. Girinti normalize edilip `diff` alındığında:

- Başlık bloğu + POST ayrıştırma (gateway 377-419 vs handler 26-71): 45 satırdan yalnızca 3'ü farklı (log çağrısı ve gateway'deki kullanılmayan `$req_reserved`).
- Onay hatası audit bloğu (gateway 513-531 vs handler 119-137): **diff çıktısı tamamen boş** — 19 satır byte-byte aynı:
```php
$abort_audit          = nicepay_get_approval_error_audit( $result );
$needs_reconciliation = $abort_audit['needs_reconciliation'];
nicepay_update_transaction( $transaction->id, array(
    'status'                => $needs_reconciliation ? 'needs_reconciliation' : 'failed',
    ... 15 satır daha ...
) );
```
- Binding hatası bloğu (gateway 550-556 vs handler 148-154): aynı.
- Başarı `$update_data` kurulumu (gateway 567-595 vs handler 161-182): `bank_code` bloğu hariç aynı.
- `is_success_code` başarı dalındaki ledger alanları (gateway 599-604 vs handler 185-191): aynı.
````

**Başarısızlık senaryosu**

Bir düzeltme `nicepay_get_approval_error_audit()` sonucuna `'result_msg' => $abort_audit['net_cancel_result_msg']` ekleyerek gateway tarafında uygulanır ama return-handler'a taşınmaz. Aynı ağ hatası bir WooCommerce ödemesinde tam audit ile kaydedilir, aynı anda gerçekleşen bir standalone ödemede `result_msg` boş kalır. Operatör mutabakat ekranında standalone kaydın neden 'needs_reconciliation' olduğunu göremez ve NICEPAY konsolunda elle aramak zorunda kalır.

**Etki**

Ödeme kabul akışının en kritik kısmı — para yakalandıktan sonraki ledger yazımı ve iptal denemesi — iki yerde bakılıyor. Bir taraftaki düzeltme diğerine taşınmazsa iki akış sessizce farklı davranmaya başlar. Ayrıca her yeni alan (ör. yeni bir audit sütunu) iki yere eklenmek zorunda; unutulursa standalone ve WooCommerce kayıtları farklı şekilde eksik kalır.

**Öneri**

Şablon-metot (template method) ile ortak gövdeyi tek sınıfa taşı:
```php
abstract class NicePay_Auth_Return_Processor {
    protected $api;
    abstract protected function flow();                    // 'woocommerce' | 'standalone'
    abstract protected function on_reject( $error_code );
    abstract protected function on_abort( array $abort );
    abstract protected function on_success( $transaction, array $result, array $update_data );
    abstract protected function on_decline( $transaction, array $update_data );

    final public function run() {
        $this->send_headers();                             // gateway 377-382 == handler 26-31
        $this->require_post();                             // gateway 384-390 == handler 33-39
        $auth_data   = $this->collect_auth_payload();      // gateway 395-419 == handler 42-71
        $transaction = NicePay_Inbound_Validator::validate_auth_return( $this->flow(), $auth_data, $this->api );
        ... ortak claim / persist / approve / bind ...
    }
}
```
`WC_Gateway_NicePay` bir `NicePay_WooCommerce_Return_Processor`, `NicePay_Return_Handler` bir `NicePay_Standalone_Return_Processor` örneği kullanır; akışa özgü tek fark (sipariş snapshot doğrulaması, `payment_complete`, makbuz e-postası) alt sınıf kancalarında kalır. Bu, yaklaşık 250 satır tekrarı eler ve `class-nicepay-return-handler.php`'nin doğrudan unit testinin olmayışını da kapatır (ortak gövde tek yerde test edilir).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: WooCommerce ve standalone dönüş işleyicileri paylaşılan ödeme-kabul gövdesini iki kez yazıyor ve ZATEN ayrışmış durumdalar. `WC_Gateway_NicePay::handle_return()` (includes/class-nicepay-gateway.php:376-689, 314 satır) ile `NicePay_Return_Handler::process()` (includes/class-nicepay-return-handler.php:25-227, 203 satır) arasında ölçülen tekrar: ~99 satır byte-byte aynı (başlık+POST ayrıştırma 377-419 vs 26-71 — 45 satırda 3 fark; onay hatası audit bloğu 513-531 vs 119-137 — diff tamamen boş; binding hatası 550-556 vs 148-154 — diff tamamen boş; $update_data kurulumu 567-595 vs 161-182 — yalnızca yorum ve BankCode farkı; ledger alanları 599-604 vs 185-191 — 1 satır farkı) artı ~60 satır yapısal olarak paralel ama gövdeleri akışa özgü kod. "~300 satır neredeyse birebir kopya" ölçüme uymuyor.

Asıl kanıt drift'in varsayımsal olmaması: (a) `bank_code`/`bank_name` yalnızca gateway:592-595'te yazılıyor; standalone akış hiç yazmıyor, oysa sütun class-nicepay-transaction-schema.php:91'de tanımlı ve admin/class-nicepay-transactions.php:453-454 bunu okuyup gösteriyor — standalone BANK ödemesi başarılı kapansa bile admin detayında banka enstrümanı boş kalıyor. (b) `active_attempt_key` kilidi handler'da success yazımıyla birlikte (191) bırakılırken gateway'de bilinçli olarak `payment_complete()` sonrasına (664) erteleniyor; aynı akışın iki farklı kilit semantiği var. Ayrıca gateway:406'daki `$req_reserved` hiçbir yerde kullanılmıyor (408-419'daki $auth_data'ya girmiyor) ve `tests/` altında `NicePay_Return_Handler` için hiçbir test yok — ortak gövdenin standalone kopyası tamamen test dışı.

Şablon-metot önerisi geçerli, ancak gerçekçi kazanım ~100 satır verbatim + ~60 satır paralel iskelet; "~250 satır" fazla iddialı.
- Gerekçe: Her satır referansını açtım ve iddia edilen blokları girinti normalize edip `diff` ile karşılaştırdım. Çekirdek iddia DOĞRU ve konum referansları dosyanın şu anki haliyle birebir eşleşiyor:

- gateway 513-531 vs handler 119-137: diff çıktısı BOŞ (byte-byte aynı) — iddia edildiği gibi.
- gateway 550-556 vs handler 148-154 (binding hatası): diff BOŞ.
- gateway 377-419 vs handler 26-71 (başlık + POST ayrıştırma): sadece 3 fark — `nicepay_log('WooCommerce return handler called')`, gateway'de kullanılmayan `$req_reserved` (395-419'daki `$auth_data`'ya hiç konmuyor), ve handler'ın 3 alanlı log çağrısı. İddia birebir doğru.
- gateway 567-595 vs handler 161-182: fark yalnızca bir yorum satırı ve `BankCode` bloğu — iddia edildiği gibi.
- gateway 599-604 vs handler 185-191: handler'da fazladan `$update_data['active_attempt_key'] = null;` (191) var; iddia "aynı" diyor, aslında 1 satır farklı.
- `grep -rln "Return_Handler" tests/` boş döndü; tests/unit listesinde return handler testi yok — "doğrudan unit testi yok" kısmı da doğru.

Çürütücü bir koruma bulamadım: ortak gövde hiçbir yardımcı fonksiyona çıkarılmamış; paylaşılan tek şey `nicepay_get_approval_error_audit()`, `nicepay_get_mismatched_approval_audit()`, `nicepay_abort_authenticated_payment()` gibi alt seviye yardımcılar — bunların ETRAFINDAKİ ledger yazımı iki yerde kopya.

İki düzeltme gerekiyor:

1) MAGNİTÜD ABARTILI. "~300 satır neredeyse birebir kopya" doğru değil. gateway `handle_return()` 376-689 = 314 satır, handler `process()` 25-227 = 203 satır. Gerçekten byte-byte aynı olan toplam ≈ 45+19+7+22+6 ≈ 99 satır. Geri kalanı (order snapshot doğrulaması 485-509, `wc_add_notice`/`update_status`/`get_checkout_payment_url` dalları, `payment_complete` try/catch 628-662, handler'ın `render_result_page`/makbuz yolu 209-218) yapısal olarak paralel ama gövdeleri farklı — bunlar şablon-metotta alt sınıf kancası olur, "birebir kopya" değil. "~250 satır tekrarı eler" önerisi de bu yüzden fazla iddialı; gerçekçi kazanım ~100 verbatim + ~60 paralel iskelet.

2) BAŞARISIZLIK SENARYOSU VARSAYIMSAL DEĞİL, ZATEN GERÇEKLEŞMİŞ — bu iddiayı zayıflatmıyor, güçlendiriyor. İddia "bir gün drift olur" diyor; oysa drift kodda ŞU AN var:
   - `bank_code`/`bank_name` sadece gateway 592-595'te yazılıyor; handler'da hiç yok. Şema (class-nicepay-transaction-schema.php:91) sütunu tanımlıyor ve admin (class-nicepay-transactions.php:453-454) gösteriyor. Yani standalone bir BANK ödemesi başarılı kapansa bile admin işlem detayında banka enstrümanı boş kalıyor.
   - `active_attempt_key` yönetimi ayrışmış: handler success dalında 191'de hemen `null`'lanıyor; gateway ise kilidi `payment_complete()` sonrasına, 664'e kadar tutuyor (629-631'deki yorum bunun bilinçli olduğunu söylüyor). Aynı "başarı" akışının iki farklı kilit semantiği var.

Bu yüzden severity `high` olarak kalmalı (hatta gerekçesi iddiadakinden daha sağlam), ama iddia metni "~300 satır" yerine ölçülmüş rakamla ve mevcut drift kanıtıyla düzeltilmeli.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: WooCommerce ve standalone dönüş işleyicileri aynı ödeme-kabul iskeletini iki kez uyguluyor ve DRIFT ZATEN GERÇEKLEŞTİ: standalone akış BankCode'u hiç kaydetmiyor.

`WC_Gateway_NicePay::handle_return()` (includes/class-nicepay-gateway.php:376-689, 314 satır) ile `NicePay_Return_Handler::process()` (includes/class-nicepay-return-handler.php:25-227, 203 satır) aynı akışı ayrı ayrı yazıyor. Girinti normalize edilip diff alındığında ~100 satır byte-byte aynı (300 değil):
- Başlık + POST ayrıştırma: gateway 377-419 vs handler 26-71, 45 satırdan 3'ü farklı
- Onay hatası audit bloğu: gateway 513-531 vs handler 119-137 — diff çıktısı tamamen boş, 19 satır aynı
- Binding hatası bloğu: gateway 550-556 vs handler 148-154 — diff boş
- `$update_data` kurulumu: gateway 567-595 vs handler 161-182, BankCode bloğu hariç aynı
- Başarı dalı ledger alanları: gateway 599-604 vs handler 185-191 aynı

SOMUT, BUGÜN ÜRETİLEBİLİR SONUÇ (hipotetik değil): gateway 591-595'teki
```php
if ( ! empty( $result['BankCode'] ) ) {
    $update_data['bank_code'] = $result['BankCode'];
    $update_data['bank_name'] = isset( $result['BankName'] ) ? $result['BankName'] : '';
}
```
bloğunun handler'da karşılığı yok (`grep -n "bank_code" includes/class-nicepay-return-handler.php` boş). `bank_code` gerçek bir sütun (class-nicepay-transaction-schema.php:91) ve admin listesi onu render ediyor (admin/class-nicepay-transactions.php:453-454). Yani standalone bir BANK ödemesi başarıyla tamamlandığında banka bilgisi kalıcı olarak kaybediliyor; aynı ödeme WooCommerce üzerinden yapılsa kaydediliyor.

Aynı kök nedenden ikinci belirti: her iki dosyada da `$auth_result_msg` (gateway:396, handler:43) ve gateway'de `$req_reserved` (406) atanıp hiç okunmuyor — kopyalanmış ölü kod.

Ayrıca `NicePay_Return_Handler` için hiç unit test yok (`grep -rln "Return_Handler" tests/` boş; tests/unit altındaki 20 dosyanın hiçbiri ona değinmiyor), yani drift'i yakalayacak bir ağ da yok.
- Gerekçe: ÇÜRÜTME DENEMESİ BAŞARISIZ — çekirdek iddia doğru ve kanıtlanabilir, ancak büyüklük iddiası ("~300 satır neredeyse birebir kopya") abartılı ve failure_scenario yanlış seçilmiş.

DOĞRULANAN KISIMLAR (birebir ölçtüm):
1. Onay hatası audit bloğu — gateway 513-531 vs handler 119-137: girinti normalize edildikten sonra `diff` çıktısı TAMAMEN BOŞ. 19 satır byte-byte aynı. Claim'in verdiği satır aralıkları tam isabetli.
2. Binding hatası bloğu — gateway 550-556 vs handler 148-154: `diff` boş, 7 satır aynı.
3. Başlık + POST ayrıştırma — gateway 377-419 vs handler 26-71: yalnız 3 fark var (gateway 392 tek argümanlı `nicepay_log`, handler 54-58 dizi argümanlı; gateway 406'da `$req_reserved`).
4. `$update_data` kurulumu ve is_success_code ledger alanları: iddia edildiği gibi (bank_code bloğu hariç) aynı.
5. Test boşluğu iddiası da doğru: `tests/unit/` altında 20 test dosyası var, hiçbiri `NicePay_Return_Handler`'a değinmiyor (`grep -rln "Return_Handler" tests/` boş döndü).

DÜZELTİLMESİ GEREKEN 1 — BÜYÜKLÜK ABARTILI:
`handle_return()` 376-689 = 314 satır; `process()` 25-227 = 203 satır. Byte-byte aynı olan bölümlerin toplamı ~100 satır (45+19+7+22+6), geri kalanı aynı iskeleti paylaşan ama akışa özgü (WC order snapshot doğrulaması 485-509, `payment_complete` try/catch 628-662, `render_result_page` çağrıları) farklı koddur. Başlıktaki "~300 satır" ve öneri metnindeki "yaklaşık 250 satır tekrarı eler" gerçekçi değil; gerçekçi kazanım ~100-130 satır.

DÜZELTİLMESİ GEREKEN 2 — FAILURE_SCENARIO YANLIŞ SEÇİLMİŞ (ve bu bulguyu GÜÇLENDİRİYOR):
Claim'in senaryosu geleceğe dönük hipotetik ("bir gün biri `result_msg` ekler ve diğerine taşımaz"). Adversaryal lens açısından bu bugün üretilemez — puanlanabilir bir kanıt değil. ANCAK aynı kök nedenden doğan drift ZATEN GERÇEKLEŞMİŞ durumda ve bugün üretilebilir:

gateway 591-595'te BankCode bloğu var, `class-nicepay-return-handler.php`'de `grep -n "BankCode\|bank_code"` HİÇBİR SONUÇ döndürmüyor. `bank_code` gerçek bir şema sütunu (class-nicepay-transaction-schema.php:91) ve admin ekranı onu okuyor (admin/class-nicepay-transactions.php:453-454). Yani: standalone bir BANK (계좌이체) ödemesi başarıyla tamamlandığında `bank_code`/`bank_name` asla yazılmaz; aynı ödeme WooCommerce üzerinden yapılsa yazılır. Operatör "İşlemler" ekranında standalone banka ödemelerinde banka adını boş görür. Bu, hipotetik değil, mevcut koddaki somut sonuç.

SEVERITY: `high` korunmalı — hafife alınmamış. Duplication normalde `medium` olurdu, ancak (a) kopya alan para yakalandıktan sonraki ledger yazımı, (b) drift zaten gerçekleşmiş ve veri kaybına yol açmış, (c) iki akıştan birinin hiç unit testi yok. Üçü birlikte `high`'ı hak ediyor.

ULAŞILABİLİRLİK: Her iki kod yolu da canlı. `handle_return()` gateway'in dönüş rotasına bağlı, `process()` standalone ReturnURL'e bağlı; ikisi de kimlik doğrulaması gerektirmeyen, NICEPAY'in POST ettiği genel uç noktalar. Ölü kod değil.

---

### ARCH-011 — WP çekirdek fonksiyonları için `function_exists()` koruması güvenlik kapılarını fail-open yapıyor ve test iskelesi üretim koduna sızmış

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | defensive-coding |
| **Konum** | [nicepay-payment-gateway.php:417](../../../nicepay-payment-gateway.php#L417) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
HTTPS zorlaması dört yerde `function_exists()` ardında ve **fonksiyon yoksa kontrol tamamen atlanıyor**:
```php
// nicepay-payment-gateway.php:417  (shortcode render)
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { return '<p ...>HTTPS gerekli</p>'; }
// nicepay-payment-gateway.php:543  (ajax_init_payment)
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { wp_send_json_error( ..., 503 ); return; }
// nicepay-payment-gateway.php:636  (ajax_refresh_payment_nonce)
if ( ... || ( function_exists( 'is_ssl' ) && ! is_ssl() ) ) { ... }
// includes/class-nicepay-gateway.php:98  (is_available)
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { return false; }
```
`is_ssl()` WordPress 2.6'dan (2008) beri çekirdekte; eklenti `Requires at least: 5.8` diyor. Bu koruma üretimde asla `false` olamaz.

Aynı desen 25 yerde: `is_ssl` ×6, `wp_next_scheduled` ×5, `nocache_headers` ×5, `is_email` ×4, `apply_filters` ×4, ayrıca `status_header`, `get_bloginfo`, `did_action`, `add_settings_error`, `remove_action`, `get_date_from_gmt`.

Kanıt: bu korumaların kaynağı test iskelesi. `tests/bootstrap/wp-stubs.php` içinde `is_ssl`, `nocache_headers`, `is_email`, `status_header` **tanımlı değil** (grep: 0 eşleşme); yalnızca `apply_filters` tanımlı (satır 76) — ve o bile 4 yerde hâlâ korumalı.

Ayrıca tutarsız: `includes/nicepay-functions.php:540` `apply_filters()`'ı korumasız çağırıyor, ama `:302-304` ve `:582` koruyor. `nicepay_check_public_rate_limit()` içinde (301) `apply_filters` yoksa filtre uygulanmadan devam ediliyor — bu iyi huylu; ama aynı fonksiyon 294'te `filter_var()` başarısız olursa **fail-open** (`return true`).
````

**Başarısızlık senaryosu**

Bir geliştirici ARCH-004'ü uygularken yeni bir `NicePay_Readiness::https_ok()` yazar ve mevcut kalıbı kopyalar: `return ! function_exists( 'is_ssl' ) || is_ssl();`. Bu artık *varsayılan olarak true* döndürür. Ardından bir mu-plugin veya güvenlik eklentisi `pluggable.php` yüklenmeden önce erken bir hook'ta bu kodu tetiklerse (`is_ssl` pluggable olmasa da benzer bir çekirdek fonksiyon için mümkündür), HTTPS kapısı sessizce açılır ve NICEPAY formu HTTP üzerinden render edilir — alıcı tarayıcısı karışık içerik uyarısı verir, `Referrer-Policy`/`X-Frame-Options` başlıkları anlamsızlaşır ve ReturnURL düz metin olarak gider.

**Etki**

Bir güvenlik kapısının 'fonksiyon yoksa geç' semantiğiyle yazılması, kapının gerçek amacını (HTTPS olmadan kart verisi akışını engellemek) belirsizleştiriyor. Daha somut olarak: bu korumalar üretimde asla tetiklenmediği için ölü dal; kod tabanında 25 test edilemez, kapsanamaz şube var. Ve en kötüsü, deseni gören bir geliştirici yeni güvenlik kontrollerini de aynı fail-open kalıbıyla yazar.

**Öneri**

1. Üretim kodundan `function_exists()` korumalarını kaldır ve çekirdek fonksiyonları doğrudan çağır:
```php
if ( ! is_ssl() ) {
    wp_send_json_error( array( 'message' => __( 'NicePay payments require HTTPS.', ... ) ), 503 );
    return;
}
```
2. Test tarafını düzelt: eksik stub'ları `tests/bootstrap/wp-stubs.php` içine ekle —
```php
if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() { return $GLOBALS['nicepay_test_is_ssl'] ?? true; }
}
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'is_email' ) )        { function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); } }
if ( ! function_exists( 'status_header' ) )   { function status_header( $c ) {} }
```
Bu, HTTPS kapalıyken davranışı *gerçekten* test edebilmeyi de sağlar (`$GLOBALS['nicepay_test_is_ssl'] = false`) — bugün mümkün değil.
3. `nicepay_check_public_rate_limit()` satır 294-298'deki fail-open'ı gözden geçir: REMOTE_ADDR yoksa istek reddedilmeli mi yoksa hız sınırı olmadan mı geçmeli? Şu anki yorum bunu savunuyor, ama karar bir `apply_filters( 'nicepay_rate_limit_fail_open', true )` ile operatöre bırakılabilir.
4. Üçüncü taraf entegrasyonu (WooCommerce) için `function_exists( 'wc_get_order' )` gibi korumalar meşru — bunları koru ve neden korunduklarını docblock'ta ayır.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: WP çekirdek fonksiyonları için gereksiz `function_exists()` korumaları ~31 ölü/test edilemez dal üretiyor ve güvenlik kontrollerine yanıltıcı "fonksiyon yoksa geç" semantiği veriyor. Kökeni eksik test stub'ları (tests/bootstrap/wp-stubs.php'de is_ssl, nocache_headers, is_email, status_header tanımsız). Bu bir HTTPS bypass zafiyeti DEĞİL — is_ssl() core'da non-pluggable olarak load.php'de tanımlıdır ve daima mevcuttur, dolayısıyla bu dallar üretimde asla tetiklenmez. Gerçek maliyet: (a) HTTPS/cron kapılarının davranışı testlerde hiç doğrulanamıyor (stub olmadığı için `is_ssl() === false` yolu koşturulamaz), (b) kalıp zaten kopyalanmış — admin/class-nicepay-admin.php:430-431 readiness panelinde `! function_exists('is_ssl') || is_ssl()` biçiminde açıkça fail-open yazılmış, yani "gelecekte olur" denen şey bugün kodda mevcut, (c) tutarsız uygulama (nicepay-functions.php:540 korumasız vs :302, :582 korumalı). Öneri geçerli: üretim kodundan core-fonksiyon korumalarını kaldır, eksik stub'ları wp-stubs.php'ye ekle, WooCommerce/PHP-eklenti korumalarını (wc_get_order, mb_strcut, curl_setopt vb.) koru. Ayrıca nicepay-functions.php:294'teki rate-limit fail-open'ı AYRI bir bulgu olarak ele alınmalı — o gerçekten erişilebilir bir davranış (REMOTE_ADDR proxy/CLI ortamında yoksa hız sınırı tamamen devre dışı kalır) ve function_exists deseniyle aynı kök nedene sahip değil.
- Gerekçe: Tüm olgusal iddiaları kodda birebir doğruladım: satır numaraları doğru, alıntılar doğru, stub eksikliği doğru, tutarsızlık doğru, sayım doğru mertebede. Çürüten bir üst-katman koruması YOK — is_ssl() WP core'da `wp-includes/load.php` içinde tanımlıdır, pluggable değildir ve eklentiler yüklenmeden çok önce mevcuttur; dolayısıyla `function_exists('is_ssl')` daima true, yani bu dallar üretimde gerçekten ölü. Aynı şekilde apply_filters/nocache_headers/is_email/status_header/wp_next_scheduled hepsi non-pluggable core fonksiyonlarıdır.

İddiayı GÜÇLENDİREN ek bir bulgu da var: iddianın "bir geliştirici gelecekte `return ! function_exists('is_ssl') || is_ssl();` yazar" öngörüsü hipotetik değil, ZATEN GERÇEKLEŞMİŞ — admin/class-nicepay-admin.php:430-431'de readiness paneli tam olarak bu fail-open kalıbını kullanıyor (`$https_ready = ! function_exists( 'is_ssl' ) || is_ssl();`). İddianın konum listesi bu iki satırı (ve admin:239, nicepay-functions.php:690/1155/1438, class-nicepay-privacy.php:167) atlamış; yani kapsam iddia edilenden GENİŞ, dar değil.

DÜZELTİLMESİ GEREKEN TEK ŞEY: severity ve çerçeveleme. İddia "güvenlik kapılarını fail-open yapıyor" diyerek `high` veriyor, ama kendi kanıtı içinde de kabul ettiği gibi bu dal üretimde asla tetiklenmiyor — sömürülebilir bir HTTPS bypass'ı YOK. "mu-plugin/güvenlik eklentisi pluggable.php'den önce tetiklerse" senaryosu is_ssl için geçerli değil (pluggable değil, load.php'de tanımlı) ve iddianın kendisi de bunu "benzer bir çekirdek fonksiyon için mümkündür" diye şartlı yazmış — yani başarısızlık senaryosu somut değil, varsayımsal. Gerçek etki: ölü dal, test edilemez şubeler, yanıltıcı güvenlik semantiği ve kopyalanan kötü kalıp (admin:430 kanıtı). Bu net bir kod-kalitesi/bakım bulgusu, `medium`. `nicepay_check_public_rate_limit()` satır 294'teki `return true` ise gerçek ve erişilebilir tek fail-open (REMOTE_ADDR yoksa hız sınırı tamamen atlanır) — ama bu ayrı bir kök neden, function_exists deseniyle ilgisi yok; ideal olarak ayrı bulgu olmalı (kural 6'nın tersi: burada iki farklı kök neden tek bulguda birleştirilmiş).

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `low`
- Düzeltilmiş iddia: `function_exists()` sarmalayıcıları WordPress çekirdek fonksiyonları etrafında 25+ yerde kullanılıyor ve bunlar **ölü/kapsanamaz dallar + tutarsız kod deseni** oluşturuyor (kod kalitesi / bakım yükü). Ancak bu bir **güvenlik zafiyeti değildir ve istismar edilebilir bir HTTPS bypass'ı yoktur**: `is_ssl()` pluggable değildir, `wp-includes/load.php` içinde `wp-settings.php` tarafından tüm eklentilerden önce koşulsuz yüklenir; dolayısıyla eklenti kodu çalıştığı her anda `function_exists('is_ssl')` daima `true`'dur ve kapı daima değerlendirilir. İddianın kendisi de bunu kabul ediyor ("üretimde asla false olamaz") — yani bildirilen failure_scenario üretilebilir değil, tamamen varsayımsal bir gelecek-geliştirici senaryosudur. Gerçek kusur: (a) 25 test edilemeyen şube, (b) `apply_filters` için aynı dosyada tutarsız kullanım (`nicepay-functions.php:540` korumasız vs `:582` korumalı), (c) `admin/class-nicepay-admin.php:430` zaten iddianın "bir geliştirici yazabilir" dediği fail-open kalıbını içeriyor (`$https_ready = ! function_exists('is_ssl') || is_ssl();`) — bu, iddiayı destekleyen ama iddiada atlanmış gerçek bir kanıt.
- Gerekçe: SATIR KANITLARI DOĞRU. Belirtilen tüm konumlar birebir eşleşti (aşağıda alıntılar). `grep` sayıları da doğrulandı: is_ssl x6, wp_next_scheduled x5, nocache_headers x5, is_email x4, apply_filters x4. `tests/bootstrap/wp-stubs.php` içinde `is_ssl`, `nocache_headers`, `is_email`, `status_header`, `did_action`, `get_date_from_gmt`, `add_settings_error`, `wp_next_scheduled`, `get_bloginfo` gerçekten 0 eşleşme; sadece `apply_filters` satır 75-76'da tanımlı. Bu kısımlar tamamen doğrulandı.

İSTİSMAR EDİLEBİLİRLİK LENSİ — İDDİA BURADA ÇÖKÜYOR:
1. Somut adım üretilemiyor. Hangi HTTP isteği? Hangi rol? Yok. `is_ssl()` WP çekirdeğinde `wp-includes/load.php` içinde koşulsuz tanımlıdır ve `pluggable.php`'de DEĞİLDİR — yani mu-plugin/güvenlik eklentisi ile "erken hook"ta bile tanımsız olamaz, çünkü `wp-settings.php` sıralamasında `load.php` mu-plugin yüklemesinden çok önce gelir. Eklenti PHP dosyaları yalnızca WP bootstrap'ı içinde çalışır. Sonuç: koşul daima değerlendirilir, HTTPS kapısı daima uygulanır. Sıfır bypass.
2. Failure_scenario metni saf spekülasyon: "Bir geliştirici ARCH-004'ü uygularken ... kopyalar". Bu bir *kod kusuru* değil, gelecekteki varsayımsal bir insan hatası. Kural 4 ("somut girdi -> somut yanlış sonuç") karşılanmıyor.
3. `severity: high` ABARTILMIŞ. Gerçek etki: kapsanamayan şubeler + okunabilirlik. Ne veri sızıntısı, ne yetki atlatma, ne para kaybı. `low` uygun; `medium` ancak "pattern yayılımı" argümanına ağırlık verilirse savunulabilir, o da spekülatif.

İDDİADAKİ FAKTİK HATA: "Bu, HTTPS kapalıyken davranışı gerçekten test edebilmeyi de sağlar — bugün mümkün değil" YANLIŞ. `tests/unit/NicePayAdminOperationsTest.php:31-35` zaten togglable bir `is_ssl()` stub'ı tanımlıyor (`$nicepay_admin_test_is_ssl` global'i). Doğru olan daha dar iddia: stub var ama satır 11 ve 155'te yalnızca `true` atanıyor, yani false yolu hiç test edilmiyor.

İDDİANIN GÖZDEN KAÇIRDIĞI, LEHİNE OLAN KANIT: `admin/class-nicepay-admin.php:430` ve `:431` zaten tam olarak korkulan fail-open kalıbını içeriyor (`! function_exists(...) || ...`). Bu, "gelecekte biri yazar" değil, "bugün yazılmış" durumdur — ama yine de üretimde tetiklenemez, sadece readiness panelinin yanlış "yeşil" göstermesi teorik riski.

RATE LIMIT FAIL-OPEN (nicepay-functions.php:294-298): satırlar doğrulandı, gerçek. Ama istismar edilebilir değil — `REMOTE_ADDR` web sunucusu tarafından set edilir, istemci tarafından kaldırılamaz. Yalnızca yanlış yapılandırılmış CLI/proxy ortamında eksik olur ve o zaman herkes için eksiktir. Ayrı ve bağımsız bir bulgu olarak `low` seviyede değerlendirilmeli; ARCH-011'e iliştirilmesi kök-neden birleştirmesi değil, şişirmedir (Kural 6 ihlali).

---

### ARCH-001 — includes/nicepay-functions.php beş ayrı katmanı barındıran 1956 satırlık god file

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | layering |
| **Konum** | [includes/nicepay-functions.php:19](../../../includes/nicepay-functions.php#L19) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Tek dosyada 59 global fonksiyon var (grep ile sayıldı) ve bunlar en az beş ayrı sorumluluğa ait:
- Loglama/redaksiyon: `nicepay_log` (19), `nicepay_redact_log_data` (55), `nicepay_filter_payment_data` (95)
- Repository/SQL: `nicepay_save_transaction` (338) … `nicepay_get_transactions` (1180) — 18 yerde `$wpdb->prefix . 'nicepay_transactions'` elle birleştiriliyor
- Para aritmetiği: `nicepay_increment_integer_string` (1462), `nicepay_normalize_amount` (1484), `nicepay_add/subtract/compare_integer_amounts` (1581-1647)
- Doğrulama: `nicepay_validate_buyer_fields` (1416), `nicepay_get_woocommerce_goods_class` (566)
- Sunum/i18n tabloları: `nicepay_get_status_label` (1671), `nicepay_get_card_name` (1695), `nicepay_get_bank_name` (1715), `nicepay_get_default_presets` (1735), `nicepay_get_preset_labels` (1814)

Ayrıca kod tabanında tablo adı iki farklı yolla üretiliyor: doğrulamalı `NicePay_Installer::table_name( $wpdb )` (yalnızca admin/class-nicepay-transactions.php:99,137) ve doğrulamasız elle birleştirme (26 yer: nicepay-functions 18, retention 4, privacy 2, installer 2).
```

**Başarısızlık senaryosu**

Bir geliştirici `nicepay_transactions` tablosunu çoklu-site paylaşımlı hale getirmek için `NicePay_Installer::table_name()`'i `$wpdb->base_prefix` kullanacak şekilde günceller. Yalnızca admin CSV/özet sorguları yeni tabloyu görür; `nicepay_save_transaction()`, `nicepay_claim_transaction_for_approval()`, `NicePay_Retention::purge_batch()` ve `NicePay_Privacy::export()` hâlâ eski `$wpdb->prefix` tablosuna yazar. Sonuç: ödeme yazılır ama admin listesinde görünmez, retention hiçbir şey silmez, GDPR export'u boş döner.

**Etki**

Repository, protokol yardımcıları ve sunum sözlükleri aynı dosyada olduğu için hiçbir katman ayrı test edilemiyor, ayrı yeniden kullanılamıyor ve her değişiklik tüm dosyayı riske atıyor. Global fonksiyon isim alanı da kirli: `nicepay_*` prefix'li 59 fonksiyon her istekte tanımlanıyor (frontend dahil), oysa `nicepay_get_card_name`/`nicepay_get_bank_name` yalnızca admin ekranında anlamlı. Tablo adının 26 farklı yerde elle üretilmesi, ileride tablo adı/prefix politikası değişirse 26 noktalı bir değişiklik demek.

**Öneri**

Dosyayı sorumluluk sınırlarına göre böl ve tablo adını tek kaynağa indir:

1. `includes/class-nicepay-transaction-repository.php` — tüm `$wpdb` erişimi (save/update/get/claim/abandon/expire/refund-attempt). Tablo adı yalnızca `NicePay_Installer::table_name()` üzerinden.
2. `includes/class-nicepay-money.php` — `normalize_amount`, `normalize_response_amount`, `normalize_ledger_amount`, `add/subtract/compare_integer_amounts`, `increment_integer_string`, `format_amount`.
3. `includes/class-nicepay-buyer.php` — `validate_buyer_fields`, `buyer_field_limits`, `utf8_byte_cut`.
4. `includes/class-nicepay-labels.php` — status/card/bank sözlükleri; yalnızca `is_admin()` altında yüklenir.
5. `includes/class-nicepay-offer-store.php` — preset/shortcode option yönetimi.

Geriye dönük uyumluluk için mevcut global fonksiyonları ince delegasyon sarmalayıcıları olarak bırak:
```php
function nicepay_normalize_amount( $amount, $currency = '' ) {
    return NicePay_Money::normalize( $amount, $currency );
}
```
Böylece testler ve üçüncü taraf kod kırılmaz, ama yeni kod sınıf API'sini kullanır.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: includes/nicefunctions.php (1956 satır, 59 global fonksiyon) loglama, repository/SQL, para aritmetiği, doğrulama ve sunum sözlüklerini tek dosyada topluyor ve `nicepay-payment-gateway.php:77`'de her istekte (frontend dahil) koşulsuz yükleniyor. Tablo adı iki yoldan üretiliyor: `NicePay_Installer::table_name()` (yalnızca admin/class-nicepay-transactions.php:99, 137) ve 26 noktada elle `$wpdb->prefix . '...'` birleştirmesi (nicepay-functions 18 = 15 transactions + 3 refund_attempts; retention 4; privacy 2; installer 2). Not: `table_name()` accessor'ı kendi içinde doğrulama YAPMIYOR (installer.php:62-64 sadece prefix birleştiriyor); `preg_match('/\A[A-Za-z0-9_]+\z/')` kontrolü çağıran iki admin noktasında ayrı ayrı tekrarlanmış. Ayrıca `nicepay_get_card_name` (1695) ve `nicepay_get_bank_name` (1715) "admin-only" değil, üretim kodunda hiç çağrılmayan ölü kod — tek tüketicileri tests/unit/NicePayFunctionsTest.php.
- Gerekçe: Çekirdek iddia doğrulandı: dosya gerçekten 1956 satır, 59 global `nicepay_*` fonksiyonu içeriyor ve verilen tüm satır numaraları (19, 55, 95, 338, 566, 1180, 1416, 1462, 1484, 1581, 1599, 1624, 1671, 1695, 1715, 1735, 1814) dosyanın şu anki haliyle birebir eşleşiyor. Dosya `nicepay-payment-gateway.php:77`'de `is_admin()` guard'ı OLMADAN, koşulsuz require ediliyor (admin dosyaları 83-86'da guard'lı) — yani sunum sözlükleri her frontend isteğinde de tanımlanıyor. Tablo adı ikiliği de gerçek: toplam 26 elle birleştirme (nicepay-functions 18, retention 4, privacy 2, installer 2) ve `NicePay_Installer::table_name()` yalnızca admin/class-nicepay-transactions.php:99 ve :137'de kullanılıyor.

Ancak üç detay yanlış/eksik:

1. **"18 yerde `$wpdb->prefix . 'nicepay_transactions'`"** yanlış. nicepay-functions.php'deki 18 birleştirmenin 15'i transactions tablosu, 3'ü (satır 777, 813, 1124) `nicepay_refund_attempts` tablosu. Tüm kod tabanında transactions için 20, refund_attempts için 6 birleştirme var. "26 yer" toplamı ve dosya bazlı dağılım (18/4/2/2) doğru.

2. **"doğrulamalı `NicePay_Installer::table_name()`"** yanlış karakterizasyon. Accessor'ın kendisinde doğrulama YOK — `class-nicepay-installer.php:62-64` sadece `return $wpdb->prefix . 'nicepay_transactions';` döndürüyor. `preg_match( '/\A[A-Za-z0-9_]+\z/', $table )` kontrolü accessor'ın içinde değil, ÇAĞIRAN yerlerde (transactions.php:100 ve :138) tekrarlanıyor. Yani accessor bugün tek fayda olarak sadece "tek kaynak" sağlıyor, güvenlik doğrulaması sağlamıyor — ve o doğrulama da kopyala-yapıştır edilmiş durumda.

3. **"`nicepay_get_card_name`/`nicepay_get_bank_name` yalnızca admin ekranında anlamlı"** yanlış — bu iki fonksiyon üretim kodunun HİÇBİR yerinde çağrılmıyor. Tüm kod tabanında (vendor hariç) tek çağıran `tests/unit/NicePayFunctionsTest.php`. Yani bunlar admin-only değil, tamamen ölü kod (ve testlerle "canlı" görünüyorlar). Bu, iddianın öngördüğünden daha güçlü bir bulgu ama farklı bir bulgu.

Severity: `high` fazla. Bugün çalışan hiçbir kod bozuk değil; başarısızlık senaryosu tamamen varsayımsal ("bir geliştirici ileride table_name()'i base_prefix'e çevirirse"). Bu bir bakım/kırılganlık riski — `medium` daha doğru. Gerçek etki, tek dosyanın 8002 satırlık includes+admin toplamının %24'ünü oluşturması ve katmanların izole test edilememesi.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: includes/nicepay-functions.php, en az beş sorumluluğu (loglama/redaksiyon, transaction repository/SQL, para aritmetiği, doğrulama, sunum-i18n sözlükleri + preset/shortcode store) tek dosyada toplayan 1956 satırlık, 59 global fonksiyonlu bir god file'dır ve her istekte frontend dahil koşulsuz yüklenir (nicepay-payment-gateway.php:77). Buna bağlı olarak `nicepay_transactions` tablo adı kanonik `NicePay_Installer::table_name()` erişimcisi (installer:63) yerine 19 çağrı yerinde elle birleştirilir (nicepay-functions 15, privacy 2, retention 2); kanonik erişimci üretim kodunda yalnızca admin/class-nicepay-transactions.php:99 ve :137'de kullanılır. Aynı sorun `nicepay_refund_attempts` tablosu için de 5 yerde tekrarlanır (nicepay-functions 777/813/1124, retention 204/268). Bu, bugün gözlemlenebilir bir hata ÜRETMEZ — her iki yol da aynı string'i döndürür ve $wpdb->prefix saldırgan kontrolünde değildir; risk latent bir bakım/tutarlılık riskidir: tablo adı veya prefix politikası (ör. multisite base_prefix) ileride değiştirilirse değişikliğin 24 noktada elle tekrarlanması gerekir ve unutulan her nokta sessizce farklı tabloya yazar. Ek olarak `nicepay_get_card_name` (1695) ve `nicepay_get_bank_name` (1715) sözlükleri üretim kodunda hiç çağrılmaz — sadece testlerde kullanılır, yani her istekte yüklenen ölü koddur.
- Gerekçe: Yapısal olgular doğru ve ben doğrudan doğruladım: dosya gerçekten 1956 satır, 59 global fonksiyon, PR'da +1600/-79 ile büyük ölçüde yeniden yazılmış ve her istekte (frontend dahil) koşulsuz yükleniyor (nicepay-payment-gateway.php:77, is_admin() bloğunun DIŞINDA). İddiada verilen tüm satır referansları dosyanın şu anki haliyle birebir eşleşiyor. Tablo adının iki farklı yolla üretildiği de doğru.

Ancak SONUÇ/İSTİSMAR EDİLEBİLİRLİK merceğinden bakınca iki ciddi düzeltme gerekiyor:

1) SAYILAR ŞİŞİRİLMİŞ. "26 yer" iddiası tutmuyor. `$wpdb->prefix . 'nicepay_transactions'` elle birleştirmesi: nicepay-functions.php'de 15 (18 değil), privacy 2, retention 2 = toplam 19 çağrı yeri. installer.php:63'teki tek örnek ise KANONİK erişimcinin kendisi (`NicePay_Installer::table_name()`), bir "duplikasyon noktası" değil — onu sayıya katmak metodolojik hata. (refund_attempts tablosu için ayrıca functions'ta 3 + retention'da 2 elle birleştirme var; iddia bunları hiç saymamış, yani kapsam da eksik.)

2) FAILURE SCENARIO BUGÜN ÜRETİLEMEZ. Senaryo somut bir HTTP isteği, kullanıcı rolü veya girdiye dayanmıyor; "gelecekte bir geliştirici table_name()'i base_prefix'e çevirirse" varsayımına dayanıyor. Şu anki kodda her iki yol da aynı string'i üretiyor (`$wpdb->prefix . 'nicepay_transactions'`), dolayısıyla çalışma zamanında sapma yok, tetiklenebilir bir hata yok. Bu bir "latent divergence risk"i, aktif bir defect değil. Ayrıca admin tarafındaki `preg_match('/\A[A-Za-z0-9_]+\z/')` doğrulaması da savunmasız birleştirmeye kıyasla yalnızca defense-in-depth; $wpdb->prefix zaten saldırgan kontrolünde değil, yani "doğrulamasız" olmak istismar edilebilir bir açık üretmiyor.

Ek olarak iddianın bir detayı yanlış: "`nicepay_get_card_name`/`nicepay_get_bank_name` yalnızca admin ekranında anlamlı" denmiş. Gerçekte bu iki fonksiyon üretim kodunun HİÇBİR yerinde çağrılmıyor — sadece testlerde. Yani "admin'e taşı" değil, "ölü kod / kullanılmayan sözlük" durumu; bu yönüyle bulgu aslında hafife alınmış ama kategorisi de yanlış konmuş.

Sonuç: mimari/bakım yükü çekirdeği doğru (god file + iki yollu tablo adı), fakat somut kullanıcı etkisi olan bir hata değil; `high` abartı. Doğru seviye `medium`.

---

### ARCH-003 — Tamamen erişilemez VBANK alt sistemi: ölü dal, ölü sütunlar, ölü indeks, ölü ayar, ölü UI ve README uyumsuzluğu

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | dead-code |
| **Konum** | [includes/class-nicepay-gateway.php:346](../../../includes/class-nicepay-gateway.php#L346) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`nicepay_get_enabled_methods()` (includes/nicepay-functions.php:1359-1361) sonucu sabit bir listeyle kesiştiriyor:
```php
$certified = array( 'CARD', 'BANK', 'CELLPHONE' );
return array_values( array_unique( array_intersect( $configured, $certified ) ) );
```
Yani `$enabled_methods` asla 'VBANK' içeremez. Buna rağmen:

1. `includes/class-nicepay-gateway.php:346-348` — `if ( in_array( 'VBANK', $enabled_methods, true ) ) { $form_data['VbankExpDate'] = ...; }` erişilemez dal.
2. `includes/class-nicepay-api.php:667-672` — `get_vbank_exp_date()` yalnızca bu ölü daldan çağrılıyor (grep: tek çağrı yeri).
3. `nicepay-payment-gateway.php:281` + `admin/class-nicepay-admin.php:152-156` — `nicepay_vbank_expiry_days` option'ı hem `add_option` ediliyor hem `register_setting` ile kaydediliyor; ancak `render_payment_tab()` (admin:698-740) bu alan için **hiçbir input üretmiyor**. Kaydedilmiş ama görünmeyen, tüketilmeyen bir ayar.
4. `includes/class-nicepay-return-handler.php:259` — `$is_vbank = ! empty( $data['VbankBankName'] ) || ! empty( $data['VbankNum'] );` Bu `$data` her zaman ya `nicepay_filter_payment_data()` çıktısı ya da `render_saved_receipt()`'in 5 alanlı dizisi. `nicepay_filter_payment_data()` allowlist'i (includes/nicepay-functions.php:100-106) `Vbank*` anahtarlarının **hiçbirini içermiyor**. Dolayısıyla `$is_vbank` her zaman `false`; satır 281-285'teki 5 CSS kuralı ve 309-330'daki 22 satırlık 'Deposit Information' bloğu erişilemez.
5. `includes/class-nicepay-transaction-schema.php:93-97` — `vbank_num`, `vbank_exp_date`, `vbank_issued_at`, `vbank_expires_at`, `vbank_deposited_at`; satır 136 — `KEY idx_vbank_expires_at (vbank_expires_at)`. Grep: `vbank_issued_at`/`vbank_expires_at`/`vbank_deposited_at` şema dışında **0 referans**.
6. `README.md:154` — 'Payment Methods | ... legacy VBank settings do not enable VBank payments' diyor, ama bu sekmede hiçbir VBank alanı yok. Dokümantasyon var olmayan bir UI'yi tarif ediyor.
````

**Başarısızlık senaryosu**

Bir bakımcı VBANK'ı etkinleştirmek için `nicepay_get_enabled_methods()` içindeki `$certified` dizisine 'VBANK' ekler ve gateway'deki `if ( in_array( 'VBANK', ... ) )` dalı ile `get_vbank_exp_date()`'in hazır olduğunu görüp iş bitti sanır. Gerçekte `nicepay_filter_payment_data()` VbankNum/VbankBankName/VbankExpDate alanlarını yanıttan atar, `render_result_page()` hesap bilgisini asla göstermez, `vbank_expires_at` sütunu hiçbir zaman dolmaz ve alıcı hangi hesaba para yatıracağını öğrenemez — ödeme tamamlanmadan askıda kalır.

**Etki**

Her `wp_insert`/`wp_update` çağrısı asla dolmayacak 5 sütunu ve sürekli NULL kalan bir sütun üzerindeki indeksi taşıyor (yazma amplifikasyonu). Yeni bir bakımcı `get_vbank_exp_date()` veya `nicepay_vbank_expiry_days` görüp VBANK'ın kısmen desteklendiğini sanabilir. `render_result_page()` içindeki 27 satırlık ölü UI, sayfanın gerçekte hangi durumları gösterdiğini okumayı zorlaştırıyor. README ise merchant'a var olmayan bir ayarı arattırıyor.

**Öneri**

Ya tamamen kaldır ya da tek bir 'gelecekte' bayrağı ardında topla. Önerilen: kaldır.
- `includes/class-nicepay-gateway.php:346-348` ve `includes/class-nicepay-api.php:664-672` sil.
- `nicepay-payment-gateway.php:281` ve `admin/class-nicepay-admin.php:152-156` sil; mevcut kurulumlar için `nicepay_vbank_expiry_days` option'ını bir sonraki şema sürümünde `delete_option()` ile temizle.
- `includes/class-nicepay-return-handler.php` içinde 259, 281-285, 293'teki `.result-vbank` referansı ve 309-330 blokları sil; `elseif` zincirini tek `if ( $success && ! empty( $data ) )` haline getir.
- Şemadan `vbank_issued_at`, `vbank_expires_at`, `vbank_deposited_at` ve `idx_vbank_expires_at` çıkar (dbDelta sütun düşürmez; ayrı bir `ALTER TABLE ... DROP` migration adımı gerekir, bu yüzden en azından yeni kurulumlarda oluşmamalarını sağla).
- `README.md:154` satırını 'Enable/disable the certified CARD, BANK and CELLPHONE methods' olarak düzelt.
VBANK gerçekten yol haritasındaysa: kaldırmak yerine `docs/` altına 'VBANK reintroduction checklist' yaz ve koddaki tüm kalıntıları tek bir `NicePay_Vbank` (henüz kaydedilmeyen) sınıfına topla.

---

### ARCH-004 — "NicePay hazır mı?" mantığı altı ayrı yerde farklı kurallarla yeniden yazılmış

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | duplication |
| **Konum** | [includes/class-nicepay-gateway.php:76](../../../includes/class-nicepay-gateway.php#L76) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aynı soruyu altı bağımsız implementasyon yanıtlıyor ve kural kümeleri örtüşmüyor:

| Yer | enabled | schema | HTTPS | mode | MID+key | currency | methods | cron |
|---|---|---|---|---|---|---|---|---|
| `WC_Gateway_NicePay::is_available()` gateway:76-103 | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | ✓ | ✗ |
| `NicePay_Admin::render_readiness_panel()` admin:432-440 | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `nicepay_get_configuration_warnings()` functions:494-532 | ✗ | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ | ✗ |
| `render_payment_shortcode()` main:395-433 | ✓(standalone) | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ |
| `ajax_init_payment()` main:533-583 | ✓(standalone) | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| `get_system_report_data()` admin:222-307 | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | ✓ |

Somut sapmalar: `is_available()` mode'un geçerliliğini hiç kontrol etmiyor (`$this->api->get_mode()` çağrısı yok) — geçersiz mode zaten MID'i boşaltıyor, ama bu dolaylı bir bağımlılık. `render_readiness_panel()` cron'u zorunlu sayarken `is_available()` saymıyor. `ajax_init_payment()` para birimini hiç kontrol etmiyor, `render_payment_shortcode()` de etmiyor — ikisi de `NicePay_Offer_Resolver`'ın KRW kontrolüne güveniyor (offer-resolver:54).
```

**Başarısızlık senaryosu**

Operatör `nicepay_expire_pending_transactions` cron'unu bir performans eklentisiyle devre dışı bırakır. `render_readiness_panel()` (admin:431) 'Recovery cron: missing' diye kırmızı uyarı verir ve panel 'NicePay is not ready for production payments' der. Ancak `is_available()` cron'u hiç kontrol etmediği için WooCommerce checkout'ta NicePay normal şekilde görünmeye devam eder; süresi dolmuş pending kayıtlar hiç temizlenmez ve `stale_approval_attempt` kurtarma işi çalışmaz. Merchant panele bakıp ödemelerin durduğunu sanır, gerçekte ödemeler alınıyor ama kurtarma yok.

**Etki**

Merchant'a gösterilen hazırlık paneli ile ödeme yüzeylerinin gerçek davranışı ayrışabilir: panel 'Core readiness checks passed' derken belirli bir yüzey hâlâ kapalı olabilir veya tersi. Yeni bir ön koşul (ör. 'webhook URL erişilebilir mi') eklendiğinde altı yerin hepsinin güncellenmesi gerekiyor ve hangisinin unutulduğu derleyici tarafından yakalanmıyor.

**Öneri**

Tek bir `NicePay_Readiness` servisine indirge ve her yüzey ondan türetsin:
```php
final class NicePay_Readiness {
    /** @return array<string,array{ok:bool,label:string,hint:string,blocking:bool}> */
    public static function checks( NicePay_API $api = null ) { /* schema, https, mode, credentials, currency, methods, cron */ }

    public static function blocks_payments( NicePay_API $api = null ) {
        foreach ( self::checks( $api ) as $c ) { if ( $c['blocking'] && ! $c['ok'] ) return true; }
        return false;
    }
}
```
Sonra:
- `is_available()` → `'yes' === $this->enabled && ! NicePay_Readiness::blocks_payments( $this->api )`
- `render_readiness_panel()` → `NicePay_Readiness::checks()` üzerinde döngü
- `get_system_report_data()` → aynı diziden `ok` bayraklarını okusun
- `render_payment_shortcode()` / `ajax_init_payment()` → `blocks_payments()` + standalone anahtarı
`nicepay_get_configuration_warnings()` yalnızca *tavsiye niteliğindeki* (test modu gibi) uyarılara indirgensin; bloklayıcı kontroller `NicePay_Readiness`'e taşınsın.

---

### ARCH-005 — NicePay_Inbound_Validator tüm hata mesajlarını `__( $degisken )` ile çeviriyor — 20 dize çıkarılamaz ve asla çevrilmez

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | i18n-architecture |
| **Konum** | [includes/class-nicepay-inbound-validator.php:297](../../../includes/class-nicepay-inbound-validator.php#L297) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
private static function error( $code, $message, $data = '' ) {
    return new WP_Error( $code, __( $message, 'nicepay-payment-gateway' ), $data );
}
```
Çağıranlar mesajı literal olarak *çağrı yerinde* veriyor ama `__()` içine değişken giriyor:
- satır 33 `'Invalid payment flow.'`, 52 `'Missing required authentication field.'`, 62/69 `'Payment flow does not match the transaction.'`, 65, 74, 79, 82, 90, 94, 100, 104, 108, 112 ve `validate_approval_response()` içinde 132, 140, 149, 154, 158, 164, 170, 175, 179 — toplam 23 dize.

`languages/nicepay-payment-gateway.pot` bu dizeleri içeremez çünkü `xgettext`/`wp i18n make-pot` yalnızca literal argümanları tarar. Doğrulama:
````

**Başarısızlık senaryosu**

Korece bir merchant, `WP_DEBUG` açıkken bir ödeme reddi loglar. `nicepay_log( 'Standalone auth return rejected', $transaction->get_error_code(), 'warning' )` (return-handler:79) kodu loglar ama destek ekibi `$transaction->get_error_message()` çıktısını incelemek istediğinde İngilizce 'Payment amount does not match the transaction.' görür — oysa aynı ekranda diğer tüm NicePay mesajları Korece. Daha kötüsü: `wp i18n make-pot` çalıştırıldığında bu 23 dize .pot'a girmediği için çevirmen ekibi eksikliği fark etmez ve sorun kalıcılaşır.

**Etki**

Bu mesajlar hiçbir dilde çevrilmez — Korece/Çince/Türkçe .po dosyalarında karşılıkları yok ve olamaz. Ayrıca WordPress.org Plugin Check ve WPCS `WordPress.WP.I18n.NonSingularStringLiteralText` kuralı bunu hata olarak işaretler; readme.txt'te WP.org yayını hedeflendiği (docs/WORDPRESS-ORG-RELEASE.md) düşünülürse bu bir yayın engeli. Bir de gereksiz çalışma zamanı maliyeti: her hata için `__()` çağrısı yapılıp hiçbir karşılık bulunamıyor.

**Öneri**

`error()` yardımcısını sadece kod + veri taşıyıcı yap, çeviriyi çağrı yerine taşı:
```php
private static function error( $code, $message, $data = '' ) {
    return new WP_Error( $code, $message, $data );   // __() YOK
}
```
ve her çağrıyı literal `__()` ile yaz:
```php
return self::error(
    'nicepay_inbound_amount_mismatch',
    __( 'Payment amount does not match the transaction.', 'nicepay-payment-gateway' )
);
```
Alternatif (daha temiz): bu mesajlar hiçbir zaman son kullanıcıya gösterilmiyor (çağıranlar `gateway:429` ve `return-handler:82` genel mesajlar kullanıyor) — o hâlde `__()`'yi tamamen kaldır ve mesajları operatör-log dizeleri olarak bırak, çevrilmeleri gerekmediğini docblock'ta belirt. Her iki durumda da CI'a `wp i18n make-pot --skip-audit` sonrası dize sayısı karşılaştırması veya PHPCS `WordPress.WP.I18n` kuralı ekle ki bu sınıf hata bir daha geçmesin.

---

### ARCH-006 — Hiçbir kodlama standardı zorlanmıyor: karışık girinti (.editorconfig ihlali), karışık Yoda, tutarsız çıktı kaçışı

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | tooling |
| **Konum** | [.editorconfig:7](../../../.editorconfig#L7) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```
`.editorconfig` tüm dosyalar için `indent_style = space`, `indent_size = 4` diyor. Gerçek durum (satır sayımı):

| Dosya | tab ile başlayan satır | 4-boşlukla başlayan satır |
|---|---|---|
| class-nicepay-retention.php | 284 | 0 |
| class-nicepay-transaction-schema.php | 256 | 0 |
| class-nicepay-installer.php | 246 | 0 |
| class-nicepay-inbound-validator.php | 244 | 0 |
| class-nicepay-privacy.php | 168 | 0 |
| class-nicepay-offer-resolver.php | 159 | 0 |
| nicepay-functions.php | 0 | 1170 |
| class-nicepay-gateway.php | 0 | 806 |
| admin/class-nicepay-admin.php | **1** | 1104 |
| includes/class-nicepay-api.php | **1** | 625 |

PR'de eklenen 6 yeni sınıf tab, mevcut 10 dosya boşluk kullanıyor; ayrıca `includes/class-nicepay-api.php:536` (`\t\t$cancelled_amount = ...`) ve `admin/class-nicepay-admin.php:362` (`\t\t\t<?php settings_errors(); ?>`) tek başına kaçmış tab satırları.

Yoda koşulları da karışık: `includes/class-nicepay-gateway.php:77` `$this->enabled !== 'yes'`, `:296` `$tx_id === false`, `admin/class-nicepay-admin.php:368` `$active_tab === 'general'`, `includes/nicepay-functions.php:1274` `$currency === 'KRW'`, `:1932` `$shortcodes === null` — buna karşılık aynı dosyalarda yüzlerce `'yes' !== ...`, `false === ...` kullanımı var.

Çıktı kaçışı tutarsız: `admin/class-nicepay-admin.php:728`, `:956` ve `templates/payment-form.php:46` ham `echo nicepay_get_method_icon( $code );`; `nicepay-payment-gateway.php:803` `'<a href="' . admin_url( ... ) . '">'` (esc_url yok); `includes/class-nicepay-gateway.php:67` `admin_url( 'admin.php?page=nicepay-settings' )` doğrudan `sprintf` içine.

Araç zinciri: `composer.json` `"lint:php": "bash .github/scripts/lint-php.sh"` ve o script yalnızca `php -l` (sözdizimi) çalıştırıyor. PHPCS/WPCS, PHPStan, Psalm yapılandırması repoda yok (`.phpcs*` dosyası yok, composer require-dev'de yalnızca phpunit).
```

**Başarısızlık senaryosu**

Eklenti WordPress.org'a gönderilir. Plugin Check taraması `WordPress.WP.I18n.NonSingularStringLiteralText` (23 kez, ARCH-005), `WordPress.Security.EscapeOutput.OutputNotEscaped` (3 kez) ve `Generic.WhiteSpace.DisallowSpaceIndent` / `ScopeIndent` (binlerce satır) hataları döker. İnceleme reddedilir ve düzeltme tüm dosyalara dokunan devasa bir diff gerektirir — bu diff içinde gerçek bir davranış değişikliğini gözden kaçırmak çok kolaydır.

**Etki**

Bir WordPress.org eklentisi için WPCS uyumu fiilen bir yayın ön koşulu; şu anda hiçbir kontrol yok. Karışık girinti her diff'i gereksiz gürültülü yapıyor ve `git blame` okunabilirliğini düşürüyor. Kaçışsız `echo` çağrıları (ikonlar merchant girdisi değil, ama `admin_url()` filtre ile değiştirilebilir) statik analiz olmadığı için hiç fark edilmiyor. En önemlisi: ARCH-005'teki `__( $degisken )` ihlali gibi gerçek hatalar CI'dan sorunsuz geçiyor.

**Öneri**

1. `composer require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer` ve kök dizine `phpcs.xml.dist`:
```xml
<ruleset name="NicePay">
  <file>includes</file><file>admin</file><file>templates</file><file>nicepay-payment-gateway.php</file>
  <rule ref="WordPress"/>
  <config name="minimum_supported_wp_version" value="5.8"/>
  <rule ref="WordPress.WP.I18n">
    <properties><property name="text_domain" type="array" value="nicepay-payment-gateway"/></properties>
  </rule>
</ruleset>
```
2. `composer.json` `quality` script'ine `"phpcs"` ekle ve `.github/workflows/tests.yml` içine bir `phpcs` adımı koy.
3. Tek seferlik `phpcbf` çalıştırıp girinti/Yoda düzeltmelerini **ayrı, davranış değiştirmeyen bir commit** olarak at (`.git-blame-ignore-revs` dosyasına ekle).
4. `composer require --dev phpstan/phpstan szepeviktor/phpstan-wordpress` ve `phpstan.neon` ile `level: 5` başlat; bu ARCH-009'daki dönüş tipi karmaşasını da yakalar.
5. `nicepay_get_method_icon()` çıktısını `wp_kses( $svg, $allowed_svg )` ile döndür ki çağrı yerlerinde ham `echo` güvenli olsun.

---

### ARCH-007 — Ölü kod envanteri: kullanılmayan değişkenler, nötrleştirilmiş parametre, hiç okunmayan sütunlar, erişilemez dallar

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | dead-code |
| **Konum** | [includes/class-nicepay-gateway.php:406](../../../includes/class-nicepay-gateway.php#L406) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aşağıdakilerin tümü grep ile doğrulandı (yalnızca tanım yeri var, tüketici yok):

1. **`$req_reserved`** — `includes/class-nicepay-gateway.php:406`: `$req_reserved = isset( $_POST['ReqReserved'] ) ? ... : '';` Tüm kod tabanında `ReqReserved`/`req_reserved` için başka **0 referans**.
2. **`$auth_result_msg`** — `includes/class-nicepay-gateway.php:396` ve `includes/class-nicepay-return-handler.php:43`'te atanıyor, ikisinde de hiç okunmuyor. NICEPAY'in hata mesajı hiçbir yere yazılmıyor.
3. **`request_cancel( ..., $extra_params = array() )`** — `includes/class-nicepay-api.php:561` imzasında var, ama satır 587: `$extra_params = is_array( $extra_params ) ? array_intersect_key( $extra_params, array() ) : array();` — boş diziyle kesişim **her zaman boş dizi**, sonraki `array_merge( $params, $extra_params )` (588) no-op. Parametre kasten nötrleştirilmiş ama imzada duruyor; tek çağrı yeri (`gateway:805`) zaten geçmiyor.
4. **`binding_token_hash`** sütunu — `includes/class-nicepay-transaction-schema.php:55`'te tanımlı; şema dosyası dışında **0 referans**. Ne yazılıyor ne okunuyor.
5. **`config_fingerprint`** sütunu — üç yerde *yazılıyor* (`includes/class-nicepay-offer-resolver.php:157`, `includes/class-nicepay-gateway.php:276-279`, `nicepay-payment-gateway.php:594`) ama hiçbir yerde *okunmuyor/karşılaştırılmıyor*. Üstelik iki yazıcı farklı formül kullanıyor: gateway `sha256(json([order_id, amount, currency, enabled_methods]))`, resolver `sha256(json(commercial-array))`. Amaçlanan 'konfigürasyon kayması tespiti' hiç uygulanmamış.
6. **Erişilemez `return`** — `nicepay-payment-gateway.php:141-142`: `wp_die( ... ); return;` — `wp_die()` sonrası `return` çalışmaz.
7. **Gereksiz predicate** — `includes/class-nicepay-retention.php:308-309`: `AND ledger.status IN ('paid', ..., 'cancelled') AND ledger.status <> 'needs_reconciliation'` — ikinci koşul mantıksal olarak her zaman doğru, çünkü `needs_reconciliation` zaten `IN` listesinde yok.
8. **Hiç yazılmayan statüler** — `'waiting'` ve `'cancelled'` durumları kod tabanında hiçbir yerde yazılmıyor (grep: `'status' => 'waiting'` ve `status = 'waiting'` → 0 sonuç; aynısı `'cancelled'` için), ama `nicepay_get_status_label()` (functions:1676,1678) ve `NicePay_Transactions::allowed_statuses()` (transactions:277) içinde yer alıyor ve **admin filtre açılır listesinde gösteriliyor** (transactions:663).
```

**Başarısızlık senaryosu**

Merchant, işlem ekranındaki 'Status' açılır listesinden 'Waiting for Deposit' seçer ve Filtrele'ye basar. Liste boş döner, hiçbir açıklama yok. Merchant sanal hesap ödemelerinin kaybolduğunu düşünüp desteğe ticket açar; oysa bu durum hiçbir zaman yazılmıyor. Aynı şekilde 'Cancelled' filtresi de her zaman boş — ancak sipariş iptal akışı (`nicepay_warn_cancelled_order_with_captured_funds`) mevcut olduğu için merchant iptallerin burada listeleneceğini makul olarak bekler.

**Etki**

Ölü kod okuyucuyu yanlış yönlendiriyor: `config_fingerprint`'in var olması bir kayma denetiminin uygulandığı izlenimini veriyor — uygulanmamış. `$auth_result_msg`'in toplanıp atılması, NICEPAY'in kimlik doğrulama hata metninin destek amaçlı elde edilebilir olduğu izlenimini veriyor — kaydedilmiyor. `waiting`/`cancelled` filtre seçenekleri merchant'a hiçbir zaman sonuç dönmeyen filtreler sunuyor.

**Öneri**

- `includes/class-nicepay-gateway.php:406` ve `:396`, `includes/class-nicepay-return-handler.php:43` satırlarını sil. `AuthResultMsg` gerçekten gerekiyorsa `nicepay_utf8_byte_cut( sanitize_text_field( $auth_result_msg ), 500 )` olarak `result_msg` sütununa yaz (başarısız kimlik doğrulama teşhisi için değerli).
- `includes/class-nicepay-api.php:561` imzasından `$extra_params = array()` parametresini ve 587-588 satırlarını kaldır.
- `binding_token_hash` sütununu şemadan çıkar.
- `config_fingerprint`'i ya gerçekten kullan ya da kaldır. Kullanmak için: `NicePay_Inbound_Validator::validate_auth_return()` içine ekle —
```php
$expected = NicePay_Offer_Resolver::fingerprint_for( $transaction );
if ( '' !== self::transaction_value( $transaction, 'config_fingerprint' )
     && ! hash_equals( self::transaction_value( $transaction, 'config_fingerprint' ), $expected ) ) {
    return self::error( 'nicepay_inbound_config_drift', ... );
}
```
ve iki yazıcıyı tek bir `NicePay_Offer_Resolver::fingerprint()` fonksiyonuna indir.
- `nicepay-payment-gateway.php:142` ve `includes/class-nicepay-retention.php:309` satırlarını sil.
- `waiting`/`cancelled` için karar ver: ya `allowed_statuses()` ve `nicepay_get_status_label()` listelerinden çıkar, ya da yazan bir akış ekle. Statü kümesini tek kaynağa taşı (bkz. ARCH-016).

---

### ARCH-008 — Şema hazırlık kontrolü her istekte (frontend dahil) en az iki `SHOW TABLES` sorgusu çalıştırıyor, önbellek yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | performance-architecture |
| **Konum** | [includes/class-nicepay-installer.php:125](../../../includes/class-nicepay-installer.php#L125) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`maybe_install()` sürüm kısa devresinden **önce** tablo varlığını sorguluyor:
```php
$current = (string) get_option( self::VERSION_OPTION, '' );   // 120
$target  = self::schema_version();                             // 121
$table   = self::table_name( $wpdb );                          // 122
$refund_table = self::refund_table_name( $wpdb );              // 123

$table_exists        = self::table_exists( $wpdb, $table );        // 125  -> SHOW TABLES LIKE
$refund_table_exists = self::table_exists( $wpdb, $refund_table );  // 126  -> SHOW TABLES LIKE

if ( $current === $target && $table_exists && $refund_table_exists ) {  // 128
    return array( 'status' => 'current', ... );
}
```
`table_exists()` (223-230) her çağrıda `$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )` + `get_var()` yapıyor, hiçbir statik/transient önbellek yok.

Bu metot `nicepay-payment-gateway.php:94` ile **`plugins_loaded` önceliği 5'te her HTTP isteğinde** çalışıyor — ana sayfa, ürün sayfası, REST çağrısı, admin-ajax, hepsinde.

Ayrıca `is_current()` (39-54) aynı iki sorguyu tekrar yapıyor ve 8 yerden çağrılıyor: `admin/class-nicepay-transactions.php:248,539`, `admin/class-nicepay-admin.php:287,433`, `includes/class-nicepay-gateway.php:81`, `nicepay-payment-gateway.php:399,538,635`. `is_available()` içindeki çağrı (gateway:81) kritik: WooCommerce checkout render'ı sırasında `WC_Payment_Gateways::get_available_payment_gateways()` her ağ geçidi için `is_available()` çağırır ve bu bir istek içinde birden fazla kez tetiklenebilir.
````

**Başarısızlık senaryosu**

200 tablolu, günde 50.000 sayfa görüntülemesi olan bir WooCommerce mağazasında eklenti günde ≥100.000 ekstra `SHOW TABLES LIKE` sorgusu üretir. Persistent object cache (Redis) kurulu olsa bile bu sorgular önbelleklenmez çünkü `$wpdb->get_var()` doğrudan gidiyor. Yoğun saatte checkout sayfası her istekte 4 ekstra round-trip alır; MySQL bağlantı havuzu doluluğunda bu, ödeme sayfasının yavaşlaması olarak görünür ve nedeni profil almadan anlaşılmaz.

**Etki**

Her sayfa görüntülemesinde en az 2, WooCommerce checkout'ta 4-6 gereksiz `SHOW TABLES` sorgusu. `SHOW TABLES LIKE` MySQL'de information_schema taraması gerektirir ve çok tablolu (çoklu eklentili) kurulumlarda ucuz değildir. Bir ödeme eklentisinin siteye getirdiği sabit maliyet, tamamen `get_option()` ile karşılanabilecek bir kontrol için ödenmiş oluyor.

**Öneri**

Tablo varlığını istek başına önbellekle ve sürüm kısa devresini öne al:
```php
private static $table_cache = array();

private static function table_exists( $wpdb, $table ) {
    if ( isset( self::$table_cache[ $table ] ) ) {
        return self::$table_cache[ $table ];
    }
    ...
    return self::$table_cache[ $table ] = ( $table === $wpdb->get_var( $query ) );
}

public static function maybe_install( $wpdb = null, $db_delta = null ) {
    ...
    // Sürüm eşleşiyorsa tabloları hiç sorgulama; şema sürümü option'ı
    // yalnızca tablolar doğrulandıktan sonra yazılıyor (satır 193-202),
    // bu yüzden eşleşen sürüm tabloların var olduğunun yeterli kanıtı.
    if ( $current === $target ) {
        return array( 'status' => 'current', 'version' => $target, ... );
    }
    $table_exists = self::table_exists( $wpdb, $table );
    ...
}
```
`is_current()` de aynı şekilde önce `get_option()` karşılaştırmasıyla kısa devre yapmalı (zaten satır 40'ta yapıyor) ve tablo doğrulamasını yalnızca `WP_DEBUG` altında ya da admin ekranlarında yapmalı. Elden düşen 'tablo elle silindi' senaryosu için ayrı bir `NicePay_Installer::verify_deep()` metodu ekleyip yalnızca sistem raporu ve hazırlık panelinden çağır.

---

### ARCH-009 — Repository katmanında beş farklı hata sinyali karışık kullanılıyor; `false` üç ayrı nedeni birbirinden ayırt edilemez kılıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-handling |
| **Konum** | [includes/nicepay-functions.php:398](../../../includes/nicepay-functions.php#L398) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Aynı katmandaki (nicepay-functions.php repository fonksiyonları) dönüş tipleri:

| Fonksiyon | Satır | Başarı | Hata |
|---|---|---|---|
| `nicepay_save_transaction` | 338 | `int` | `false` |
| `nicepay_update_transaction` | 398 | `true` | `false` |
| `nicepay_get_transaction` | 466 | `object` | `null` |
| `nicepay_claim_transaction_for_approval` | 653 | `true` | `false` |
| `nicepay_save_refund_attempt` | 763 | `int` | `false` |
| `nicepay_expire_pending_transactions` | 954 | `int` | `0` |
| `nicepay_issue_standalone_receipt` | 1052 | `array` | `false` |
| `nicepay_validate_buyer_fields` | 1416 | `array` | `WP_Error` |
| `nicepay_normalize_amount` | 1484 | `string` | `false` |
| `nicepay_get_woocommerce_goods_class` | 566 | `string` | `false` |

`nicepay_update_transaction()` özellikle sorunlu — üç tamamen farklı durum aynı `false`'u üretiyor:
```php
$prepared = NicePay_Transaction_Schema::prepare_write( $data );
if ( empty( $prepared['data'] ) ) {
    return false;                              // 408-410: yazılacak geçerli sütun yok (programlama hatası)
}
$result = $wpdb->update( ... );                // 412
$succeeded = $require_change ? 1 === (int) $result : false !== $result;   // 420
//   $result === false        -> veritabanı hatası
//   $result === 0            -> satır bulunamadı / değer zaten aynı
```
Aynı şekilde `nicepay_expire_pending_transactions()` (954-985) hem 'hiçbir şey süresi dolmadı' hem 'iki sorgu da başarısız' durumunda `0` dönüyor (satır 967 ve 981 `$count = 0`).
````

**Başarısızlık senaryosu**

Bir alıcı NICEPAY penceresinden döner. Ağ katmanında (proxy/CDN retry) POST iki kez ulaşır ama ikinci istek `nicepay_claim_transaction_for_approval()`'ı geçemeden önce ilk istek `tid`/`auth_token` yazmış olur. İkinci isteğin `nicepay_update_transaction( $id, array('tid'=>..., 'auth_token'=>...), true )` çağrısı MySQL'den `0` alır (değerler zaten aynı, `UPDATE` etkilenen satır bildirmez). Kod bunu `false` olarak görür, `nicepay_abort_authenticated_payment()` çalışır ve NICEPAY'e net-cancel gider. Sipariş `on-hold` olur ve alıcının ödemesi iptal edilir — hâlbuki ilk istek başarıyla ilerliyordu.

(Not: `claim` mekanizması pratikte ikinci isteği daha erken durdurur; senaryonun asıl geçerliliği MySQL'in `CLIENT_FOUND_ROWS` ayarına ve retry zamanlamasına bağlıdır. Yine de dönüş tipi bu ayrımı yapamadığı için kod bunu kanıtlayamıyor.)

**Etki**

Çağıranlar hata nedenini ayırt edemediği için tümü aynı ağır kurtarma yolunu seçmek zorunda. `includes/class-nicepay-gateway.php:461-481`: `nicepay_update_transaction(..., true)` `false` dönerse — ki bu 'satır zaten aynı TID'e sahip' de olabilir — kod hemen `nicepay_abort_authenticated_payment()` çağırıp NICEPAY'de bir net-cancel tetikliyor. İyi huylu bir 'değişiklik yok' durumu, gerçek bir para tersine çevirmesine yol açabiliyor.

**Öneri**

Repository katmanında tek bir sözleşme belirle ve nedeni taşı:
```php
/**
 * @return true|WP_Error  'nicepay_db_error' | 'nicepay_no_change' | 'nicepay_nothing_to_write'
 */
function nicepay_update_transaction( $id, $data, $require_change = false ) {
    $prepared = NicePay_Transaction_Schema::prepare_write( $data );
    if ( empty( $prepared['data'] ) ) {
        return new WP_Error( 'nicepay_nothing_to_write', ..., array( 'keys' => array_keys( (array) $data ) ) );
    }
    $result = $wpdb->update( ... );
    if ( false === $result ) {
        return new WP_Error( 'nicepay_db_error', ... );
    }
    if ( $require_change && 1 !== (int) $result ) {
        return new WP_Error( 'nicepay_no_change', ..., array( 'rows' => (int) $result ) );
    }
    return true;
}
```
Çağıranlar `is_wp_error()` + `get_error_code()` ile ayırsın: `nicepay_db_error` → abort + net-cancel; `nicepay_no_change` → idempotent kabul edip devam; `nicepay_nothing_to_write` → `nicepay_log(..., 'error')` + abort (programlama hatası).

Geçiş için: yeni bir `nicepay_update_transaction_result()` ekle, mevcut fonksiyonu `! is_wp_error( ... )` sarmalayıcısı yap, çağıranları tek tek taşı. Aynı deseni `nicepay_save_transaction` (int|WP_Error) ve `nicepay_expire_pending_transactions` (array{expired:int,stale:int}|WP_Error) için uygula.

---

### ARCH-010 — En kötü beş metot: 434 / 314 / 295 / 225 / 203 satır — hiçbiri parçalanmamış

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | complexity |
| **Konum** | [admin/class-nicepay-transactions.php:534](../../../admin/class-nicepay-transactions.php#L534) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Otomatik ölçüm (metot açılışından kapanış süslü parantezine kadar satır sayısı):

| # | Satır | Konum | Metot |
|---|---|---|---|
| 1 | **434** | admin/class-nicepay-transactions.php:534-967 | `render()` |
| 2 | **314** | includes/class-nicepay-gateway.php:376-689 | `handle_return()` |
| 3 | **295** | admin/class-nicepay-admin.php:818-1112 | `render_shortcode_generator_tab()` |
| 4 | **225** | includes/class-nicepay-gateway.php:694-918 | `process_refund()` |
| 5 | **203** | includes/class-nicepay-return-handler.php:25-227 | `process()` |
| 6 | 162 | includes/class-nicepay-gateway.php:210-371 | `generate_payment_form()` |
| 7 | 143 | admin/class-nicepay-admin.php:465-607 | `render_general_tab()` |
| 8 | 134 | includes/class-nicepay-offer-resolver.php:31-164 | `resolve_standalone()` |
| 9 | 123 | includes/class-nicepay-return-handler.php:251-373 | `render_result_page()` |
| 10 | 111 | nicepay-payment-gateway.php:655-765 | `ajax_save_shortcode()` |

`render()` tek metotta yetki kontrolü, şema kontrolü, filtre ayrıştırma, sayfalama, üç ayrı sorgu (`nicepay_get_transactions`, `get_financial_summary`, `nicepay_get_refund_attempts_for_transactions`), export URL kurulumu ve ~370 satır inline HTML yapıyor (534-596 mantık, 597-966 çıktı).

`process_refund()` 225 satırda 9 ayrı erken-dönüş kapısı (703, 711, 717, 731, 737, 746, 756, 763, 766, 785, 798) + 4 farklı sonuç dalı barındırıyor.

`ajax_save_shortcode()` içindeki tek `if` (686-694) 6 satıra yayılmış 8 ayrı doğrulama koşulunu `||` ile birleştirip **tek bir genel hata mesajı** üretiyor:
```php
if ( false === $amount || '' === $goods_name || ! in_array( $goods_class, array( '0', '1' ), true ) ||
    ( '' !== $pay_method && ! array_key_exists( $pay_method, NicePay_API::get_certified_methods() ) ) ||
    ! in_array( $display_mode, array( 'inline', 'modal' ), true ) ||
    ! in_array( $language, array( '', 'KO', 'EN', 'CN' ), true ) ||
    ( '' !== $buyer_email && function_exists( 'is_email' ) && ! is_email( $buyer_email ) ) ||
    ( '' !== $buyer_tel && ( strlen( $buyer_tel ) > 30 || ! preg_match( '/^[0-9+() -]{7,30}$/', $buyer_tel ) ) ) ) {
    wp_send_json_error( array( 'message' => __( 'Payment configuration contains an invalid amount, product, method, or buyer field.', ... ) ), 400 );
```
````

**Başarısızlık senaryosu**

Merchant, shortcode oluşturucuda telefon numarasını `+90 (532) 123 45 67 ext.4` olarak girer (30 karakterden uzun ve regex dışı). AJAX 400 döner ve 'Payment configuration contains an invalid amount, product, method, or buyer field.' mesajı gösterilir. Merchant tutarı, ürün adını, ödeme yöntemini ve dili tek tek deneyerek hangisinin sorunlu olduğunu bulmaya çalışır; telefon alanının uzunluk sınırı hiçbir yerde yazmadığı için (HTML `maxlength` yok) sorunu bulamaz ve konfigürasyonu kaydedemez.

**Etki**

Bu metotların hiçbiri parça parça test edilemiyor; `NicePayTransactionsAdminTest` (120 satır) yalnızca `parse_filters`/`get_safe_detail_fields` gibi statik yardımcıları test ediyor, 434 satırlık `render()` hiç kapsanmıyor. `handle_return()` ve `process_refund()` para akışının tam merkezinde ve her ikisi de bir ekranda görünmüyor — bir dalı değiştirirken diğerinin varsayımlarını kırmak çok kolay. `ajax_save_shortcode()`'un tek mesajı ise merchant'a hangi alanın hatalı olduğunu söylemiyor.

**Öneri**

Öncelik sırasıyla:
1. `render()`: mantık kısmını `prepare_view_model()` (534-596) olarak ayır, HTML'i `admin/views/transactions-list.php` template dosyasına taşı — `templates/` deseni zaten var. Her tablo satırı için `render_row( $item, $refund_attempts )` özel metodu.
2. `handle_return()` ve `process()`: ARCH-002'deki şablon-metot refaktörü ikisini birden ~80 satıra indirir.
3. `process_refund()`: kapıları ayrı metotlara böl —
```php
$gate = $this->assert_refundable( $order, $transaction );        // 703-748
if ( is_wp_error( $gate ) ) return $gate;
$gate = $this->assert_woocommerce_reconciled( $order, $refunded, $cancel_amt );  // 750-759
if ( is_wp_error( $gate ) ) return $gate;
$gate = $this->assert_partial_allowed( $transaction, $is_partial );              // 761-776
if ( is_wp_error( $gate ) ) return $gate;
```
4. `ajax_save_shortcode()`: doğrulamayı alan-alan hata döndüren bir `NicePay_Shortcode_Config::validate( array $input )` sınıfına taşı ve `WP_Error` içine `array( 'field' => 'buyer_tel' )` koy; JS bu alanı vurgulasın. Mesajlar spesifik olsun: 'Telefon numarası en fazla 30 karakter olabilir ve yalnızca rakam, boşluk, +, (, ), - içerebilir.'

---

### ARCH-012 — Kod tabanında hiç `do_action()` yok — üçüncü taraflar için tek bir yaşam döngüsü olayı bulunmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | extensibility |
| **Konum** | [includes/class-nicepay-gateway.php:639](../../../includes/class-nicepay-gateway.php#L639) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Tam kod tabanı taraması:
```
$ grep -rn "do_action(" includes admin templates nicepay-payment-gateway.php
(sonuç yok)
```
Mevcut tüm genişletme noktaları 7 filtreden ibaret:
- `nicepay_http_timeout` (includes/class-nicepay-api.php:211)
- `nicepay_http_connect_timeout` (includes/class-nicepay-api.php:245)
- `nicepay_public_rate_limit_identity` / `_max_requests` / `_window` (includes/nicepay-functions.php:302-304)
- `nicepay_manage_transactions_capability` (includes/nicepay-functions.php:540)
- `nicepay_goods_cl` (includes/nicepay-functions.php:583)

Kritik olay noktalarının hiçbiri yayınlanmıyor:
- Ödeme onaylandı — `includes/class-nicepay-gateway.php:639` (`$order->payment_complete( $tid )` hemen sonrası) ve `includes/class-nicepay-return-handler.php:218` (standalone)
- Ödeme reddedildi — gateway:676, return-handler:223
- Mutabakat gerekli oldu — gateway:517-530, `includes/nicepay-functions.php:247-263`
- İade tamamlandı / reddedildi — gateway:852-863, gateway:900-912
- Standalone makbuz düzenlendi — `includes/nicepay-functions.php:1077`
- Retention silme yapıldı — `includes/class-nicepay-retention.php:171`

Ayrıca kritik filtre eksikleri: `goods_name` üretimi (gateway:222-238) filtresiz, `$form_data` dizisi (gateway:329-343) filtresiz, ReturnURL (gateway:214) filtresiz, makbuz e-postası konu/gövdesi (`includes/nicepay-functions.php:1159-1169`) filtresiz, başarı/başarısızlık yönlendirmeleri (gateway:668, 687) filtresiz.
````

**Başarısızlık senaryosu**

Merchant, mutabakat gerektiren her işlemde muhasebe ekibine e-posta göndermek ister. Kodda hiç `do_action` olmadığı için tek yol: `update_option`/`wpdb` sorgularını cron ile poll etmek ya da `nicepay_transactions` tablosunu doğrudan okumak. Bu tablo eklentinin özel şeması (VERSION `2026.08.24.7`) olduğundan bir sonraki şema güncellemesinde entegrasyon sessizce kırılır. Alternatif olarak merchant eklentiyi fork'lar ve bir sonraki güvenlik güncellemesini alamaz hale gelir.

**Etki**

Bir ödeme ağ geçidi ekosistemin merkezindedir: ERP senkronizasyonu, muhasebe entegrasyonu, Slack/SMS bildirimi, özel makbuz şablonu, sadakat puanı — bunların hiçbiri bu eklentiye bağlanamıyor. Merchant'ın tek seçeneği ya kodu fork'lamak ya da WooCommerce'ın genel `woocommerce_payment_complete` hook'una bağlanıp NicePay'e özgü bağlamı (TID, mutabakat durumu, net-cancel sonucu) kaybetmek. Standalone akışında ise WooCommerce hook'u da yok — hiçbir entegrasyon noktası kalmıyor.

**Öneri**

Para durumu değişikliklerini yayınla. Yalnızca kalıcı yazma başarılı olduktan *sonra* ateşle ki dinleyiciler tutarlı veri görsün:
```php
// includes/class-nicepay-gateway.php:664 civarı, active_attempt_key serbest bırakıldıktan sonra
do_action( 'nicepay_payment_approved', $transaction->id, $order->get_id(), array(
    'flow'      => 'woocommerce',
    'tid'       => $tid,
    'moid'      => $transaction->moid,
    'amount'    => $transaction->amount,
    'currency'  => $transaction->currency,
    'method'    => $result_method,
    'mode'      => $transaction->mode,
) );
```
Minimum olay kümesi (her ikisi de `woocommerce` ve `standalone` akışı için tek imzayla):
- `nicepay_payment_approved( $transaction_id, $source_ref, array $context )`
- `nicepay_payment_failed( $transaction_id, $source_ref, $result_code )`
- `nicepay_payment_needs_reconciliation( $transaction_id, $reason_code, array $audit )`
- `nicepay_refund_completed( $transaction_id, $cancel_moid, $amount, $is_partial )`
- `nicepay_refund_rejected( $transaction_id, $cancel_moid, $result_code )`
- `nicepay_retention_purged( $deleted_count, $days )`

Filtre olarak eklenecekler:
- `apply_filters( 'nicepay_goods_name', $goods_name, $order )` (gateway:238)
- `apply_filters( 'nicepay_auth_form_data', $form_data, $order, $transaction_id )` (gateway:343) — **MID/Amt/SignData/Moid alanlarını filtre sonrası zorla geri yaz** ki imza bağı bozulamasın
- `apply_filters( 'nicepay_return_url', $return_url, $order )` (gateway:214)
- `apply_filters( 'nicepay_receipt_email_subject' / '_body', ... )` (functions:1159/1160)
- `apply_filters( 'nicepay_payment_success_redirect', $url, $order )` (gateway:668)

Hepsini `docs/` altında bir 'Hooks reference' sayfasına yaz — şu anda README'de hook listesi yok.

---

### ARCH-013 — Üç ayrı redaksiyon implementasyonu, üç bağımsız hassas-alan listesi

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | duplication |
| **Konum** | [includes/nicepay-functions.php:55](../../../includes/nicepay-functions.php#L55) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aynı problemi (hassas veriyi çıktıdan/depodan uzak tutmak) üç farklı strateji ve üç ayrı bakılan liste çözüyor:

1. **Anahtar tabanlı deny-list** — `nicepay_redact_log_data()` (includes/nicepay-functions.php:55-84). Liste (56-62): `authtoken, signdata, signature, merchantkey, buyername, buyeremail, buyertel, cardno, cardnumber, cardtoken, vbanknum, refundacctnum, refundacctno, accountnumber, accountno, authcode`.
2. **Alan allow-list** — `nicepay_filter_payment_data()` (includes/nicepay-functions.php:95-116). Liste (100-106): `ResultCode, ResultMsg, TID, MID, Moid, Amt, PayMethod, AuthCode, AuthDate, CardCode, CardName, CardQuota, BankCode, BankName, CancelAmt, CancelNum, CancelDate, CancelTime, CcPartCl, ClickpayCl, CardType, ErrorCD, ErrorMsg`.
3. **Regex tabanlı metin temizleme** — `NicePay_Transactions::redact_sensitive_text()` (admin/class-nicepay-transactions.php:508-532). Deseni (520): `auth_?token|signature|sign_?data|merchant_?key|card_?(?:no|number)|pan|buyer_?(?:email|tel)|account_?(?:no|number)|vbank_?num` + e-posta + 9-19 haneli sayı + 32+ hex/40+ base64.

Çelişki: (1) `AuthCode`'u `authcode` olarak **redakte ediyor**, (2) `AuthCode`'u **allowlist'e alıp saklıyor** (satır 101), (3) `AuthCode`'u hiç tanımıyor ama `admin/class-nicepay-transactions.php:495` `sanitize_result_code()` üzerinden serbest bırakabiliyor. Aynı alan üç katmanda üç farklı muamele görüyor. Ayrıca `pan` ve `card_type` yalnızca (3)'te, `CardType` yalnızca (2)'de var.
```

**Başarısızlık senaryosu**

NICEPAY yeni bir protokol sürümünde onay yanıtına `BuyerAuthNo` (alıcı kimlik doğrulama numarası) alanını ekler. `nicepay_redact_log_data()` bunu tanımaz (`buyerauthno` deny-list'te yok) → `WP_DEBUG` açık bir sitede tam değer WooCommerce log dosyasına yazılır. `nicepay_filter_payment_data()` allowlist olduğu için sütuna yazmaz (iyi). `redact_sensitive_text()` deseni de tanımaz. Sonuç: aynı veri bir katmanda sızarken diğerinde korunur ve bu tutarsızlık ancak bir log denetiminde fark edilir.

**Etki**

Yeni bir hassas alan (ör. NICEPAY'in ileride ekleyeceği `MallReserved` veya `CardInterest`) eklendiğinde üç listeyi birden güncellemek gerekiyor ve hangisinin unutulduğunu hiçbir test yakalamıyor — üç fonksiyonun ayrı ayrı testleri var ama aralarındaki tutarlılığı test eden hiçbir şey yok. Redaksiyon politikası kod tabanında bir yerde yazılı değil.

**Öneri**

Tek bir politika sınıfı kur ve üç fonksiyonu ondan besle:
```php
final class NicePay_Redaction_Policy {
    /** Log/görüntüleme sırasında maskelenecek normalize anahtarlar. */
    public static function denied_keys() {
        return array( 'authtoken', 'signdata', 'signature', 'merchantkey',
            'buyername', 'buyeremail', 'buyertel', 'cardno', 'cardnumber',
            'cardtoken', 'vbanknum', 'refundacctnum', 'refundacctno',
            'accountnumber', 'accountno', 'authcode' );
    }
    /** payment_data sütununa kalıcı yazılabilecek protokol alanları. */
    public static function persistable_fields() { return array( 'ResultCode', ... ); }
    /** Serbest metinde maskelenecek desenler. */
    public static function text_patterns() { return array( ... ); }

    /** CI güvencesi: allowlist ile deny-list çakışmamalı. */
    public static function assert_consistent() {
        foreach ( self::persistable_fields() as $f ) {
            $k = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $f ) );
            if ( in_array( $k, self::denied_keys(), true ) ) {
                throw new LogicException( "Field {$f} is both persistable and denied." );
            }
        }
    }
}
```
Üç fonksiyon da bu sınıftan okusun. `assert_consistent()`'i bir PHPUnit testi olarak çalıştır — bu test bugün `AuthCode` çelişkisini anında yakalar ve o çelişkiyi bilinçli bir karara zorlar (AuthCode ya loglanabilir ya da saklanamaz; ikisi birden tutarsız).

---

### ARCH-014 — Aynı işlem listesi filtresi iki ayrı SQL implementasyonuyla çözülüyor; ikisi farklı doğrulama kurallarına sahip

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | duplication |
| **Konum** | [includes/nicepay-functions.php:1180](../../../includes/nicepay-functions.php#L1180) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Admin işlem ekranı **aynı filtre kümesini** iki farklı motorla sorguluyor:

**A) Dinamik WHERE üreteci** — `nicepay_get_transactions()` (includes/nicepay-functions.php:1199-1254):
```php
$where = array( '1=1' );
if ( $args['status'] )         { $where[] = 'status = %s';        $values[] = $args['status']; }
if ( $args['payment_method'] ) { $where[] = 'payment_method = %s'; $values[] = $args['payment_method']; }
...
$where_clause = implode( ' AND ', $where );
```
Hiçbir statü/method allowlist doğrulaması yok — çağıranın doğrulamış olmasına güveniyor.

**B) Sabit şekilli hazır SQL** — `NicePay_Transactions::FILTER_SQL` (admin/class-nicepay-transactions.php:17-21) + `filter_query_values()` (313-349), ki bu **yeniden doğruluyor**:
```php
$status = isset( $filters['status'] ) && in_array( $filters['status'], self::allowed_statuses(), true ) ? $filters['status'] : '';
$method = isset( $filters['payment_method'] ) && in_array( $filters['payment_method'], array_keys( NicePay_API::get_available_methods() ), true ) ? $filters['payment_method'] : '';
```

Kullanım (admin/class-nicepay-transactions.php:557-565):
```php
$result = $filter_state['valid'] ? nicepay_get_transactions( $args ) : ...;   // A
$financial_summary = $filter_state['valid'] ? self::get_financial_summary( $filter_state['filters'] ) : array();  // B
```
Yani **aynı ekranda liste A ile, mali özet B ile geliyor**. CSV export'u da B kullanıyor (write_csv_export:159-170).

Ek sapmalar: A `flow` filtresini desteklemiyor, B'nin çıktısı olan CSV `flow` sütununu ihraç ediyor; A `ORDER BY {$orderby} {$order}` ile sıralanabiliyor (1250) ama admin bu parametreyi hiç geçmiyor — `orderby`/`order` argümanları ölü.
````

**Başarısızlık senaryosu**

Bir geliştirici `allowed_statuses()`'a yeni bir `'chargeback'` statüsü ekler (transactions:277) ama `parse_filters` üzerinden gelen değer A yoluna da geçer. `nicepay_get_transactions()` bunu sorgulayıp 12 satır döner. `filter_query_values()` ise `allowed_statuses()`'ı kullandığı için aynı değeri kabul eder — bu senaryoda tutarlı. Ancak ters yön: geliştirici `nicepay_get_transactions()`'a bir `flow` filtresi ekler ve admin'e `filter_flow` girdisi bağlar. Liste yalnızca `standalone` satırlarını gösterirken `get_financial_summary()` `flow`'u bilmediği için **tüm akışların** toplamını gösterir. Merchant 3 standalone işlem görürken 'Toplam yakalanan: 4.500.000 KRW' okur ve tutarsızlığın nedenini anlayamaz.

**Etki**

Liste ve mali özet aynı sayfada farklı SQL yollarından geldiği için doğrulama kuralları ayrışırsa toplamlar listeyle uyuşmaz — mali mutabakat ekranı için ciddi bir güven sorunu. İki motoru senkron tutmak tamamen manuel; hiçbir test 'A ve B aynı satır kümesini döndürür' iddiasını doğrulamıyor.

**Öneri**

A'yı ortadan kaldır, B'yi tek motor yap:
```php
// admin/class-nicepay-transactions.php
public static function query_page( array $filters, $page, $per_page ) {
    global $wpdb;
    $table  = NicePay_Installer::table_name( $wpdb );
    $values = self::filter_query_values( $filters );
    $values[] = (int) $per_page;
    $values[] = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
    $sql = "SELECT " . self::LIST_COLUMNS . " FROM {$table} WHERE " . self::FILTER_SQL
         . " ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
    return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
}
public static function count_page( array $filters ) { /* aynı FILTER_SQL ile COUNT(*) */ }
```
`nicepay_get_transactions()`'ı bu metoda delege eden bir sarmalayıcıya indir (geri uyumluluk + `NicePayTransactionRepositoryTest:323` testi korunur). Ardından liste, özet ve CSV'nin **aynı `FILTER_SQL` sabitini ve aynı `filter_query_values()` çıktısını** kullandığını doğrulayan bir entegrasyon testi ekle:
```php
public function test_list_and_summary_agree_on_row_count(): void {
    $filters = array( 'status' => 'paid', ... );
    $this->assertSame(
        (int) NicePay_Transactions::count_page( $filters ),
        array_sum( wp_list_pluck( NicePay_Transactions::get_financial_summary( $filters ), 'transaction_count' ) )
    );
}
```
Ayrıca `nicepay_get_transactions()`'ın kullanılmayan `orderby`/`order` argümanlarını (functions:1193-1194, 1233-1235) ya UI'ya bağla ya da kaldır.

---

### ARCH-015 — "Sertifikalı ödeme yöntemi" ve "geçerli statü" listeleri üçer/ikişer yerde elle tekrarlanıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | single-source-of-truth |
| **Konum** | [includes/nicepay-functions.php:1359](../../../includes/nicepay-functions.php#L1359) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
**Sertifikalı yöntem listesi üç yerde:**
1. `includes/nicepay-functions.php:1359` — `$certified = array( 'CARD', 'BANK', 'CELLPHONE' );`
2. `includes/class-nicepay-api.php:712-718` — `get_certified_methods()` → `array( 'CARD' => ..., 'BANK' => ..., 'CELLPHONE' => ... )`
3. `includes/class-nicepay-offer-resolver.php:18` — `const VERIFIED_METHODS = array( 'CARD', 'BANK', 'CELLPHONE' );`

Bunlara ek olarak **iki farklı allowlist aynı ayar için kullanılıyor**:
- `admin/class-nicepay-admin.php:203` — `sanitize_enabled_methods()` → `array_keys( NicePay_API::get_certified_methods() )` (3 yöntem)
- `admin/class-nicepay-admin.php:236` — `get_system_report_data()` → `array_keys( NicePay_API::get_available_methods() )` (6 yöntem)
Aynı `nicepay_enabled_methods` option'ı biri 3 biri 6 elemanlı listeye göre süzülüyor.

**Statü listesi iki yerde:**
1. `includes/nicepay-functions.php:1672-1684` — `nicepay_get_status_label()` 11 anahtar
2. `admin/class-nicepay-transactions.php:277` — `allowed_statuses()` 11 eleman
Bugün kümeler birebir aynı, ama iki ayrı literal olarak bakılıyor. Üçüncü bir örtük küme SQL'de: `includes/class-nicepay-retention.php:308` `IN ('paid', 'partially_refunded', 'refunded', 'failed', 'abandoned', 'expired', 'cancelled')` — 7 eleman, `pending`/`approving`/`needs_reconciliation`/`waiting` yok.
```

**Başarısızlık senaryosu**

Geliştirici VBANK sertifikasyonunu tamamlar ve `includes/class-nicepay-api.php:712` `get_certified_methods()` içine `'VBANK'` ekler. Admin Payment Methods sekmesinde VBANK checkbox'ı görünür ve `sanitize_enabled_methods()` (admin:203) değeri kabul eder. Ancak `includes/nicepay-functions.php:1359`'daki `$certified` dizisi güncellenmediği için `nicepay_get_enabled_methods()` VBANK'ı süzer ve boş liste kalırsa `is_available()` (gateway:89) `false` döner. Merchant VBANK'ı işaretler, kaydeder, checkbox işaretli kalır — ama NicePay checkout'tan tamamen kaybolur. Hiçbir hata mesajı yok; merchant ayarın kaydedilmediğini sanır.

**Etki**

Bir yöntemin sertifikasyonu tamamlandığında (ör. VBANK) üç literal listesini de bulup güncellemek gerekiyor; biri unutulursa yöntem yarı-etkin bir duruma düşer (ör. ayarda seçilebilir ama `NicePay_Offer_Resolver` reddeder). Statü tarafında da yeni bir statü eklendiğinde etiketi eklenmezse admin ham `snake_case` değeri gösterir (`nicepay_get_status_label()` satır 1686 fallback), filtre listesine eklenmezse süzülemez, retention predikatına eklenmezse asla silinmez.

**Öneri**

Yöntem ve statü kümelerini birer sabit sınıfa taşı:
```php
final class NicePay_Methods {
    /** Protokolde bilinen tüm yöntemler (geçmiş kayıtlar için). */
    const KNOWN = array( 'CARD', 'BANK', 'VBANK', 'CELLPHONE', 'SSG_BANK', 'GIFT_CULT' );
    /** Yeni ödeme isteği için sertifikalı alt küme. */
    const CERTIFIED = array( 'CARD', 'BANK', 'CELLPHONE' );
    public static function labels( array $codes ) { /* __() ile etiketle */ }
}

final class NicePay_Status {
    const PENDING = 'pending'; const APPROVING = 'approving'; const PAID = 'paid';
    const FAILED = 'failed';  const CANCELLED = 'cancelled';  const REFUNDED = 'refunded';
    const PARTIALLY_REFUNDED = 'partially_refunded';
    const NEEDS_RECONCILIATION = 'needs_reconciliation';
    const ABANDONED = 'abandoned'; const EXPIRED = 'expired'; const WAITING = 'waiting';
    public static function all() { return array( self::PENDING, ... ); }
    /** Retention'ın silebileceği terminal statüler. */
    public static function terminal() { return array( self::PAID, self::PARTIALLY_REFUNDED, ... ); }
    public static function label( $status ) { /* tek etiket tablosu */ }
}
```
Ardından: `nicepay_get_enabled_methods()` → `NicePay_Methods::CERTIFIED`; `get_certified_methods()` → `NicePay_Methods::labels( NicePay_Methods::CERTIFIED )`; `NicePay_Offer_Resolver::VERIFIED_METHODS` sil, doğrudan `NicePay_Methods::CERTIFIED` kullan; `allowed_statuses()` → `NicePay_Status::all()`; `nicepay_get_status_label()` → `NicePay_Status::label()`; `NicePay_Retention::eligible_row_predicate()` içindeki SQL literal listesi `implode( "','", NicePay_Status::terminal() )` ile üretilsin. `admin/class-nicepay-admin.php:236`'daki `get_available_methods()` kullanımını `get_certified_methods()` ile hizala (sistem raporu, ayarın gerçekte hangi kümeye göre süzüldüğünü göstermeli).

---

### ARCH-016 — WooCommerce sipariş yazımı etrafında tutarsız istisna koruması: kritik yollarda try/catch var, aynı derecede kritik yollarda yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-handling |
| **Konum** | [includes/nicepay-functions.php:1024](../../../includes/nicepay-functions.php#L1024) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`$order->save()` / `$order->update_status()` çağrıları bazı yerlerde `try/catch ( Throwable )` içinde, aynı riskteki diğer yerlerde çıplak:

**Korumalı (4 yer):**
- `includes/class-nicepay-gateway.php:187-201` — `recover_paid_order_from_ledger()` içinde `payment_complete` + `save`
- `includes/class-nicepay-gateway.php:308-327` — `generate_payment_form()` içinde meta + `save`
- `includes/class-nicepay-gateway.php:628-662` — başarı yolunda `payment_complete` + `save` (iç içe ikinci `catch` de var, 657)
- `nicepay-payment-gateway.php:135-139 / 179-183 / 229-233` — `switch_to_blog` + `finally`

**Korumasız (aynı riskte):**
- `includes/nicepay-functions.php:1020-1024` — `nicepay_warn_cancelled_order_with_captured_funds()`:
```php
$order->add_order_note( __( 'Warning: cancelling ...' ) );
$order->update_meta_data( '_nicepay_cancelled_funds_warning', 'yes' );
$order->save();      // 1024 — try/catch YOK
```
Bu fonksiyon `woocommerce_order_status_cancelled` hook'una bağlı (nicepay-payment-gateway.php:107).
- `includes/class-nicepay-gateway.php:683` — reddedilen ödeme yolunda `$order->save()`
- `includes/class-nicepay-gateway.php:826` — iade sonucu bilinmiyor yolunda `$order->save()`
- `includes/class-nicepay-gateway.php:875` — iade onaylandı ama audit başarısız yolunda `$order->save()`
- `includes/class-nicepay-gateway.php:477, 505, 537, 559, 623` — abort/on-hold yollarında `$order->save()`
````

**Başarısızlık senaryosu**

Merchant, NicePay ile ödenmiş bir siparişi WooCommerce admin'den 'Cancelled' yapar. `nicepay_warn_cancelled_order_with_captured_funds()` çalışır ve `$order->save()` (functions:1024) çağrılır. Sitede kurulu bir sipariş-senkronizasyon eklentisi `woocommerce_after_order_object_save` içinde harici API'ye gider ve zaman aşımında `RuntimeException` fırlatır. İstisna yakalanmaz → PHP fatal error. Sonuç: sipariş durumu geçişi yarım kalır, merchant beyaz ekran görür ve — en kritiği — 'iptal ödemeyi geri getirmez, bakiyeyi kontrol edin' uyarı notu siparişe **hiç eklenmez**. Merchant siparişin iptal edildiğini ve paranın iade edildiğini varsayar; para NICEPAY'de yakalanmış kalır.

**Etki**

WooCommerce `save()` HPOS altında veritabanı hatasında, `wc_get_order` filtrelerinde ya da bir üçüncü taraf `woocommerce_before_order_object_save` hook'unda `Exception` fırlatabilir. Korumasız çağrılarda bu istisna PHP fatal'e dönüşür ve dosyanın geri kalanı (ledger yazımı, yönlendirme) hiç çalışmaz. Korumanın rastgele dağılımı, hangi yolun kritik sayıldığına dair bir mimari karar olmadığını gösteriyor.

**Öneri**

Sipariş yazımını tek bir güvenli yardımcıya topla ve tüm çağrıları ondan geçir:
```php
/**
 * WooCommerce sipariş yazımını izole eder; üçüncü taraf hook istisnaları
 * NicePay para akışını asla durdurmamalıdır.
 *
 * @return bool Yazma başarılı mı.
 */
function nicepay_safe_order_save( $order, $context ) {
    try {
        $order->save();
        return true;
    } catch ( Throwable $throwable ) {
        nicepay_log( 'WooCommerce order save failed', array( 'context' => $context, 'order_id' => $order->get_id() ), 'error' );
        return false;
    }
}
```
ve `includes/nicepay-functions.php:1024`, `includes/class-nicepay-gateway.php:477, 505, 537, 559, 623, 683, 826, 875` satırlarını `nicepay_safe_order_save( $order, '...' )` ile değiştir.

Ayrıca `nicepay_warn_cancelled_order_with_captured_funds()` özelinde: `add_order_note()` de aynı korumaya alınmalı ve yazma başarısız olursa en azından `nicepay_log(..., 'error')` ile kalıcı iz bırakılmalı — bu, merchant'ın gözden kaçırdığı 'yakalanmış para' durumunun tek uyarı kanalı.

---

### ARCH-017 — NicePay_Transaction_Schema::prepare_write() tanımadığı sütunu sessizce atıyor — yazma hatası hiçbir sinyal üretmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | fail-silent |
| **Konum** | [includes/class-nicepay-transaction-schema.php:262](../../../includes/class-nicepay-transaction-schema.php#L262) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
public static function prepare_write( array $data ) {
    $database_owned = array( 'id', 'created_at', 'updated_at' );
    $prepared       = array();
    $formats        = array();

    foreach ( $data as $column => $value ) {
        if ( in_array( $column, $database_owned, true ) || null === self::format_for( $column ) ) {
            continue;                     // <- 264: sessizce atlanıyor, log yok, sayaç yok
        }
        $prepared[ $column ] = $value;
        $formats[]            = self::format_for( $column );
    }

    return array( 'data' => $prepared, 'formats' => $formats );
}
```
Çağıran `nicepay_update_transaction()` (includes/nicepay-functions.php:407-410) yalnızca **tümü** atıldıysa fark ediyor:
```php
$prepared = NicePay_Transaction_Schema::prepare_write( $data );
if ( empty( $prepared['data'] ) ) { return false; }
```
Kısmi atma tamamen sessiz. Bugün üretimde canlı bir kayıp yok — 4 çağıran dosyadaki tüm literal anahtarları şema sütunlarıyla karşılaştırdım, uyumsuz anahtar **0** — ama mekanizma tamamen korumasız.

Ek not: `format_for()` (237-247) her çağrıda `self::columns()`'ı (66 elemanlı dizi) yeniden kuruyor ve `prepare_write` döngüsünde sütun başına **iki kez** çağrılıyor (263 ve 268) → 20 alanlı bir güncelleme 40 kez 66-elemanlı dizi inşa ediyor.
````

**Başarısızlık senaryosu**

Bir düzeltme, mutabakat notunu daha ayrıntılı hale getirmek için `includes/class-nicepay-gateway.php:869`'daki anahtarı `'reconciliation_note'` yerine `'reconciliation_notes'` (çoğul) olarak yazar. `prepare_write()` bu anahtarı sessizce atar, kalan `status`/`reconciliation_status`/`cancel_status` alanları yazılır, `$wpdb->update` 1 döner, `nicepay_update_transaction` `true` döner. İade onaylanmış ama yerel audit başarısız olmuş bir işlem `needs_reconciliation` olarak işaretlenir — ancak **nedeni boş kalır**. Operatör mutabakat ekranında (admin/class-nicepay-transactions.php:917) 'Manual review required' genel metnini görür ve iadenin gerçekten NICEPAY tarafında geçip geçmediğini anlamak için tek tek konsol araması yapmak zorunda kalır.

**Etki**

Bir yazım hatası veya bir sütun yeniden adlandırması, `nicepay_update_transaction( ..., true )` **`true` dönerken** ilgili alanın hiç yazılmaması anlamına gelir — çünkü `$require_change` kontrolü kalan alanlar yazıldığı için 1 satır etkilenmiş görür. Bu, para mutabakatı alanları için sessiz veri kaybı riski taşıyor ve hiçbir test bunu yakalayamaz (`NicePayTransactionSchemaTest` yalnızca bilinen sütunları test ediyor).

**Öneri**

Bilinmeyen sütunu hata olarak ele al ve `format_for` maliyetini kaldır:
```php
private static $format_cache = null;

public static function format_for( $column ) {
    if ( null === self::$format_cache ) {
        self::$format_cache = array();
        foreach ( array_keys( self::columns() ) as $name ) {
            self::$format_cache[ $name ] = in_array( $name, array( 'id', 'wc_order_id', 'approval_attempts' ), true ) ? '%d' : '%s';
        }
    }
    return isset( self::$format_cache[ $column ] ) ? self::$format_cache[ $column ] : null;
}

public static function prepare_write( array $data ) {
    $database_owned = array( 'id', 'created_at', 'updated_at' );
    $prepared = array(); $formats = array(); $rejected = array();

    foreach ( $data as $column => $value ) {
        if ( in_array( $column, $database_owned, true ) ) { continue; }
        $format = self::format_for( $column );
        if ( null === $format ) { $rejected[] = $column; continue; }
        $prepared[ $column ] = $value;
        $formats[]           = $format;
    }

    return array( 'data' => $prepared, 'formats' => $formats, 'rejected' => $rejected );
}
```
ve `nicepay_update_transaction()` / `nicepay_save_transaction()` içinde:
```php
if ( ! empty( $prepared['rejected'] ) ) {
    nicepay_log( 'Transaction write contained unknown columns', array( 'columns' => $prepared['rejected'] ), 'error' );
}
```
Ek güvence: `WP_DEBUG` altında `throw new LogicException()` at ki geliştirme sırasında anında patlasın. Bir de PHPUnit testi ekle: `prepare_write( array( 'not_a_column' => 'x' ) )` çağrısı `rejected` içinde `not_a_column` döndürmeli.

---

### ARCH-018 — Blocks adaptörü `supports` listesini gateway'den türetmek yerine sabit yazıyor; iade desteği Blocks'a yanlış bildiriliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | consistency |
| **Konum** | [includes/class-nicepay-blocks-integration.php:69](../../../includes/class-nicepay-blocks-integration.php#L69) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Gateway kendini şöyle tanıtıyor (`includes/class-nicepay-gateway.php:22`):
```php
$this->supports = array( 'products', 'refunds' );
```
Blocks adaptörü ise (`includes/class-nicepay-blocks-integration.php:66-74`) `$this->gateway` elinde olduğu hâlde sabit yazıyor:
```php
return array(
    'title'          => wp_strip_all_tags( (string) $title ),
    'description'    => wp_strip_all_tags( (string) $description ),
    'supports'       => array( 'products' ),        // <- 'refunds' YOK, gateway'den okunmuyor
    'is_available'   => $this->is_active(),
    ...
);
```
`$title`/`$description` gateway'den okunuyor (59-64) ama `supports` okunmuyor — aynı metot içinde tutarsız yaklaşım.

İkinci tutarsızlık: eklenti Blocks uyumluluğunu **bilinçli olarak beyan etmiyor** (`nicepay-payment-gateway.php:36-50`, docblock: 'Checkout Blocks compatibility remains intentionally undeclared until the browser checkout matrix passes') ama aynı anda `woocommerce_blocks_loaded` üzerinden bir Blocks ödeme yöntemi **kaydediyor** (`nicepay-payment-gateway.php:96, 312-328`).
````

**Başarısızlık senaryosu**

Merchant WooCommerce'ı Blocks checkout'a geçirir. WooCommerce Status ekranı NicePay'i 'Blocks ile uyumsuz' listeler ve merchant bir uyumluluk uyarısı görür; ancak checkout'ta NicePay normal görünür ve ödemeler geçer. Bir süre sonra merchant bir iade yapmak ister; Blocks bağlamına bakan bir sipariş yönetim eklentisi `supports['refunds']` `false` olduğu için iade butonunu gizler. Merchant, eklentinin iadeyi desteklemediği sonucuna varır ve iadeyi NICEPAY konsolundan elle yapar — bu, ARCH-002/`process_refund` içindeki tüm ledger mutabakatını atlar ve `refunded_amount` sütunu güncellenmeden kalır.

**Etki**

WooCommerce, `supports` listesini Blocks tarafında özellik keşfi için kullanır. `refunds` bildirilmediği için Blocks bağlamındaki araçlar bu ağ geçidinin iade edemediğini varsayar — oysa `process_refund()` (gateway:694) tam işlevsel. Ayrıca `supports` sabit olduğu için gateway'e ileride `tokenization` veya `subscriptions` eklenirse Blocks tarafı sessizce geride kalır. Uyumluluk beyanı çelişkisi ise merchant'a WooCommerce > Status > Plugins ekranında 'NicePay: incompatible with Cart and Checkout Blocks' uyarısı gösterirken ödeme yönteminin Blocks checkout'ta çalışmasına yol açar — merchant hangi bilgiye güveneceğini bilemez.

**Öneri**

1. `supports`'ı gateway'den türet:
```php
'supports' => $this->gateway instanceof WC_Gateway_NicePay
    ? array_values( (array) $this->gateway->supports )
    : array( 'products' ),
```
2. Beyan çelişkisini çöz. İki tutarlı seçenek var:
   - **(a) Adaptörü de geri çek:** `nicepay-payment-gateway.php:96`'daki `woocommerce_blocks_loaded` kaydını bir bayrağın (`nicepay_blocks_adapter_enabled`, varsayılan `no`) arkasına al. Beyan yok + kayıt yok → tutarlı ve merchant'a net.
   - **(b) Uyumluluğu beyan et:** `nicepay_declare_woocommerce_compatibility()` içine `declare_compatibility( 'cart_checkout_blocks', NICEPAY_PLUGIN_FILE, true )` ekle ve `tests/integration/woocommerce-smoke.php`'ye Blocks checkout senaryosunu ekle.
   
   Hangisi seçilirse seçilsin `nicepay-payment-gateway.php:36-39` docblock'u, `README.md:141` ve `readme.txt:69` ile aynı hikâyeyi anlatmalı — şu anda kod 'kayıtlı ama beyan edilmemiş' üçüncü bir durumda.
3. `NicePayBlocksIntegrationTest`'e bir doğrulama ekle: `get_payment_method_data()['supports']` gateway'in `supports` dizisiyle birebir eşleşmeli.

---

### ARCH-019 — Sunum katmanı sınırları tutarsız: bir akış tam HTML dokümanını sınıf içine gömüyor, bir template ise iş kuralı doğruluyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | layering |
| **Konum** | [includes/class-nicepay-return-handler.php:251](../../../includes/class-nicepay-return-handler.php#L251) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Eklentide bir `templates/` dizini var (`payment-form.php`, `standalone-payment-form.php`) ama sunum yaklaşımı üç farklı şekilde uygulanmış:

1. **Sınıf içine gömülü tam doküman** — `NicePay_Return_Handler::render_result_page()` (251-373, 123 satır): `<!DOCTYPE html>`'den `</html>`'ye kadar tam sayfa + 28 satır inline `<style>` (267-295) bir private metot içinde. `templates/payment-result.php` yok.

2. **Template dosyası, ama örtük değişken sözleşmesiyle** — `templates/payment-form.php`, çağıranın (`includes/class-nicepay-gateway.php:370` `include`) yerel değişkenlerine güveniyor: `$order`, `$form_data`, `$enabled_methods`, `$is_test_mode`. Hiçbir `extract()`/parametre yok; gateway'de bir yerel değişken yeniden adlandırılırsa template sessizce `Undefined variable` uyarısı üretir ve bozuk form render eder.

3. **İş kuralı doğrulayan template** — `templates/standalone-payment-form.php:16-69` sunumdan önce iş doğrulaması yapıyor: tutar geçerliliği (40-45), etkin yöntem kontrolü (47-51), kayıtlı yöntemin hâlâ etkin olup olmadığı (53-57). Bu kontroller `render_payment_shortcode()` (nicepay-payment-gateway.php:426-433) ve `NicePay_Offer_Resolver::resolve_standalone()` içinde zaten yapılmış. Ayrıca satır 42'de para karşılaştırması **float** ile yapılıyor:
```php
if ( empty( $amount ) || (float) $amount <= 0 ) {
```
kod tabanının geri kalanı `nicepay_compare_integer_amounts()` string-tamsayı disiplinini uyguluyor (includes/nicepay-functions.php:1581).

Ek kırılganlık: `templates/standalone-payment-form.php:62` DOM id'sini rastgele üretiyor — `$form_id = 'nicepay-standalone-' . wp_rand( 1000, 9999 );` Aynı sayfada iki shortcode varsa çakışma olasılığı ~1/9000; `tests/js/nicepay-multi-instance.test.js` çoklu örnek senaryosunu test ettiğine göre bu senaryo destekleniyor.
````

**Başarısızlık senaryosu**

Merchant sonuç sayfasını kendi marka renkleriyle uyumlu hale getirmek ister. `templates/` dizinine bakar, `payment-result.php` bulamaz, `locate_template()` deseni de yok. Tek seçenek eklentiyi fork'lamak veya `render_result_page()` çıktısını `ob_start` ile yakalayıp regex ile değiştirmeye çalışmaktır — ikisi de sürdürülebilir değil. Bu arada alıcı, siteyle hiç uyuşmayan jenerik mavi bir sayfada 'Payment Successful' görür ve doğru sitede olduğundan emin olamaz.

**Etki**

Sonuç sayfası (alıcının ödeme sonrası gördüğü tek ekran) tema tarafından geçersiz kılınamıyor, çevrilebilir olsa da yeniden stillendirilemiyor ve `render_result_page()`'in 123 satırı `process()`'in okunabilirliğini bozuyor. Template'lerin iş kuralı doğrulaması, aynı kuralın iki yerde bakılmasına yol açıyor. Float karşılaştırması, ekibin başka her yerde titizlikle kaçındığı bir kalıbı geri sokuyor.

**Öneri**

1. `render_result_page()` gövdesini `templates/payment-result.php` dosyasına taşı ve tema geçersiz kılmasını destekle:
```php
private function render_result_page( $success, $message, $data = array() ) {
    status_header( $success ? 200 : 400 );
    $template = locate_template( 'nicepay/payment-result.php' );
    if ( ! $template ) {
        $template = NICEPAY_PLUGIN_DIR . 'templates/payment-result.php';
    }
    $template = apply_filters( 'nicepay_payment_result_template', $template, $success, $data );
    include $template;
}
```
CSS'i `assets/css/nicepay-result.css` dosyasına çıkar ve `wp_enqueue_style` yerine (bu sayfa `wp_head` çalıştırmıyor) `<link>` ile ver ya da dosyayı `file_get_contents` ile inline et.
2. Template değişken sözleşmesini açık hale getir — `include` yerine dizi geçen bir yükleyici kullan:
```php
function nicepay_load_template( $relative_path, array $vars ) {
    extract( $vars, EXTR_SKIP );
    include NICEPAY_PLUGIN_DIR . 'templates/' . $relative_path;
}
// gateway:370
nicepay_load_template( 'payment-form.php', compact( 'order', 'form_data', 'enabled_methods', 'is_test_mode' ) );
```
3. `templates/standalone-payment-form.php:40-57`'deki doğrulamayı kaldır; `render_payment_shortcode()` zaten `NicePay_Offer_Resolver` üzerinden doğruluyor. Template yalnızca `$offer` dizisinden gelen hazır değerleri render etmeli. Satır 42'deki float karşılaştırması `false === nicepay_normalize_amount( $atts['amount'], $currency )` ile değiştirilmeli.
4. `$form_id`'yi deterministik yap: `static $instance = 0; $form_id = 'nicepay-standalone-' . $config_id . '-' . ( ++$instance );`

---

### ARCH-020 — Autoloader yok; 13 elle yazılmış require_once ve sınıflar arası örtük yükleme-sırası bağımlılığı

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | dependency-management |
| **Konum** | [nicepay-payment-gateway.php:74](../../../nicepay-payment-gateway.php#L74) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`includes()` (74-88) sabit sırayla 8 dosya yüklüyor; ek 5 `require_once` dağınık yerlerde (300 gateway, 317 blocks, 360 ve 388 return-handler). Composer `autoload` bölümü yok (`composer.json` yalnızca `autoload-dev` tanımlıyor).

Bağımlılıklar örtük — hiçbir sınıf kendi bağımlılığını beyan etmiyor:
- `includes/class-nicepay-api.php` `nicepay_log()`, `nicepay_normalize_amount()`, `nicepay_normalize_response_amount()`, `nicepay_utf8_byte_cut()` kullanıyor; `require_once` yok, `nicepay-payment-gateway.php:77`'nin 78'den önce çalışmasına güveniyor.
- `includes/class-nicepay-gateway.php` `NicePay_Installer`, `NicePay_Inbound_Validator`, `NicePay_API`, 15+ `nicepay_*` fonksiyonu kullanıyor; hiçbir `require` yok.
- `includes/class-nicepay-retention.php` (satır 76'da, functions'tan **önce** yükleniyor) `nicepay_log()` çağırıyor (149, 172) — çalışma zamanında çağrıldığı için bugün sorun yok, ama sınıf yükleme sırası bir sözleşme değil.

Yalnızca iki dosya bağımlılığını beyan ediyor: `includes/nicepay-functions.php:10` ve `includes/class-nicepay-installer.php:15`, ikisi de aynı `class-nicepay-transaction-schema.php`'yi (mükerrer, `require_once` sayesinde zararsız).
```

**Başarısızlık senaryosu**

Bir geliştirici `NicePay_Retention`'ın ayar doğrulamasına para birimi kontrolü ekler ve sınıf gövdesinde (metot içinde değil) `NicePay_Money::CURRENCY` sabitini kullanan bir `const` tanımlar. `class-nicepay-retention.php` `nicepay-functions.php`'den **önce** yüklendiği için (satır 76 vs 77) `Class 'NicePay_Money' not found` fatal'i oluşur — ama yalnızca `plugins_loaded` çalıştığında, yani sitenin tamamı beyaz ekrana düşer. Hata yükleme sırasından kaynaklandığı için `class-nicepay-retention.php`'de yapılan değişikliği incelemek nedeni göstermez.

**Etki**

Yeni bir sınıf eklemek `includes()` listesini elle güncellemeyi gerektiriyor ve sıra hatası ancak çalışma zamanında `Class not found` fatal'i olarak ortaya çıkıyor. Ayrıca her istekte 8 dosya koşulsuz yükleniyor — `nicepay-icons.php` (75 satır SVG) ve `class-nicepay-privacy.php` yalnızca ilgili hook tetiklendiğinde gerekli. Test tarafında da her test dosyası bağımlılık zincirini elle kurmak zorunda.

**Öneri**

PSR-4 olmayan WordPress adlandırmasıyla uyumlu bir eşleme tabanlı autoloader ekle:
```php
spl_autoload_register( static function ( $class ) {
    static $map = array(
        'NicePay_Installer'            => 'includes/class-nicepay-installer.php',
        'NicePay_Transaction_Schema'   => 'includes/class-nicepay-transaction-schema.php',
        'NicePay_API'                  => 'includes/class-nicepay-api.php',
        'NicePay_Offer_Resolver'       => 'includes/class-nicepay-offer-resolver.php',
        'NicePay_Inbound_Validator'    => 'includes/class-nicepay-inbound-validator.php',
        'NicePay_Retention'            => 'includes/class-nicepay-retention.php',
        'NicePay_Privacy'              => 'includes/class-nicepay-privacy.php',
        'NicePay_Return_Handler'       => 'includes/class-nicepay-return-handler.php',
        'NicePay_Blocks_Integration'   => 'includes/class-nicepay-blocks-integration.php',
        'WC_Gateway_NicePay'           => 'includes/class-nicepay-gateway.php',
        'NicePay_Admin'                => 'admin/class-nicepay-admin.php',
        'NicePay_Transactions'         => 'admin/class-nicepay-transactions.php',
    );
    if ( isset( $map[ $class ] ) ) {
        require_once NICEPAY_PLUGIN_DIR . $map[ $class ];
    }
} );
```
Ardından `includes()` yalnızca gerçekten global fonksiyon tanımlayan iki dosyayı yüklesin:
```php
private function includes() {
    require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-functions.php';
    if ( is_admin() ) {
        require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-icons.php';
    }
}
```
(`nicepay-icons.php` yalnızca admin ve `templates/payment-form.php`'de kullanılıyor; template zaten gateway ile birlikte yükleniyorsa `wp_enqueue_scripts` yolunda da yüklenmeli — bunu `enqueue_payment_assets()` içine taşı.)

Autoloader eklendikten sonra `NicePay_Admin` ve `NicePay_Transactions` sınıflarının kendilerini örneklemesini de düzelt (bkz. ARCH-021), çünkü autoload ile dosya yalnızca sınıfa erişildiğinde yüklenir ve dosya sonundaki `new` artık hook kaydı için güvenilir olmaz.

---

### ARCH-021 — Admin sınıfları hook'ları constructor yan etkisi olarak kaydediyor ve dosya sonunda kendilerini örnekliyor; NicePay_Transactions her sayfa render'ında ikinci kez örnekleniyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | lifecycle |
| **Konum** | [admin/class-nicepay-transactions.php:1030](../../../admin/class-nicepay-transactions.php#L1030) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Her iki admin sınıfı da dosya kapsamında kendini örnekliyor:
```php
// admin/class-nicepay-transactions.php:1030
new NicePay_Transactions();
// admin/class-nicepay-admin.php:1199
new NicePay_Admin();
```
ve hook kaydı constructor'da yapılıyor:
```php
// admin/class-nicepay-transactions.php:23-26
public function __construct() {
    add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );
    add_action( 'admin_post_nicepay_export_transactions', array( $this, 'handle_csv_export' ) );
}
```
Ancak `NicePay_Admin::render_transactions_page()` (admin/class-nicepay-admin.php:1114-1117) **ikinci bir örnek** yaratıyor:
```php
public function render_transactions_page() {
    $transactions_page = new NicePay_Transactions();
    $transactions_page->render();
}
```
WordPress `_wp_filter_build_unique_id()` nesne metotları için `spl_object_hash()` kullandığından farklı nesne = farklı benzersiz kimlik → `wp_ajax_nicepay_cancel_transaction` ve `admin_post_nicepay_export_transactions` **ikinci kez kaydediliyor**.

Bu, plugin'in geri kalanındaki desenle de çelişiyor: `NicePay_Payment_Gateway::init_hooks()` (nicepay-payment-gateway.php:90-128) tüm kayıtları merkezî ve tekil olarak yapıyor.
````

**Başarısızlık senaryosu**

ARCH-020'deki autoloader eklenir ve `includes()` içindeki `require_once 'admin/class-nicepay-transactions.php'` satırı kaldırılır. `admin-post.php?action=nicepay_export_transactions` isteğinde hiçbir kod `NicePay_Transactions` sınıfına dokunmadığı için dosya hiç yüklenmez, `admin_post_nicepay_export_transactions` hook'u kaydedilmez ve WordPress isteği `admin-post.php`'nin varsayılan 'geçersiz istek' dalına düşürür. Merchant CSV dışa aktarma butonuna basar, boş bir sayfaya yönlendirilir ve hiçbir hata mesajı görmez.

**Etki**

Bugün gözle görülür bir hata üretmiyor (mükerrer kayıt yalnızca işlemler ekranı render edilirken oluşuyor, o istekte hiçbir AJAX/admin-post hook'u ateşlenmiyor). Ancak sınıf oluşturmanın global yan etkisi olması, sınıfı test etmeyi ve yeniden kullanmayı zorlaştırıyor — bir birim testi `new NicePay_Transactions()` yaptığında hook tablosunu kirletiyor. Ayrıca autoloader'a geçiş (ARCH-020) bu deseni doğrudan kırar: autoload ile dosya yalnızca sınıfa ilk erişimde yüklenir, dolayısıyla `admin_init` sırasında sınıfa dokunulmazsa hook'lar hiç kaydedilmez.

**Öneri**

Hook kaydını constructor'dan ayırıp merkezî bootstrap'a taşı:
```php
// admin/class-nicepay-transactions.php — dosya sonundaki `new` silinir
class NicePay_Transactions {
    public static function register_hooks() {
        $instance = new self();
        add_action( 'wp_ajax_nicepay_cancel_transaction', array( $instance, 'ajax_cancel_transaction' ) );
        add_action( 'admin_post_nicepay_export_transactions', array( $instance, 'handle_csv_export' ) );
    }
    public function __construct() {}   // yan etkisiz
}

// nicepay-payment-gateway.php::init_hooks()
if ( is_admin() ) {
    NicePay_Admin::register_hooks();
    NicePay_Transactions::register_hooks();
    ...
}
```
`render_transactions_page()` artık güvenle `( new NicePay_Transactions() )->render()` yapabilir çünkü constructor yan etkisiz. Aynı düzeltmeyi `admin/class-nicepay-admin.php:1199` için de uygula.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenen (baştan sona okundu): nicepay-payment-gateway.php (827), includes/nicepay-functions.php (1956), includes/class-nicepay-gateway.php (919), includes/class-nicepay-api.php (739), includes/class-nicepay-return-handler.php (374), includes/class-nicepay-installer.php (301), includes/class-nicepay-transaction-schema.php (296, kısmi — sütun listesinin ortası atlandı, indeks/format/prepare bölümleri tam), includes/class-nicepay-inbound-validator.php (299), includes/class-nicepay-offer-resolver.php (198), includes/class-nicepay-retention.php (340), includes/class-nicepay-privacy.php (200), includes/class-nicepay-blocks-integration.php (76), includes/nicepay-icons.php (75), admin/class-nicepay-admin.php (1199 — 470-690 ve 750-820 aralıkları yalnızca hedefli grep ile tarandı, tam okunmadı), admin/class-nicepay-transactions.php (1030 — 597-965 arası HTML render bloğu yalnızca hedefli okundu), templates/payment-form.php (77), templates/standalone-payment-form.php (ilk 70 satır tam, kalan 116 satır HTML render yalnızca grep).

Sistematik olarak uygulanan kontroller: PHP 8.0-8.3'e özgü sözdizimi (str_contains/str_starts_with/array_is_list/?->/match/enum/readonly/never/ctor promotion/first-class callable) — hiçbiri bulunmadı, 7.4 uyumu doğrulandı; 8.x kaldırılanlar (create_function/each/${} interpolasyonu) — yok; gevşek karşılaştırma ve strict-olmayan in_array — yok; @ bastırma ve boş catch — yok; declare(strict_types) — hiçbir dosyada yok (WordPress ekosisteminde normal, bulgu olarak raporlanmadı); require/include zinciri tam haritalandı; do_action/apply_filters envanteri çıkarıldı; girinti stili dosya bazında ölçüldü; metot uzunlukları AST-benzeri bir sayaçla ölçülüp sıralandı; şemaya yazılan tüm anahtarlar sütun listesiyle programatik olarak karşılaştırıldı (uyumsuz anahtar bulunmadı).

İncelenemedi / kapsam dışı bırakıldı: (1) `php` ikili dosyası bu ortamda mevcut olmadığı için `php -l` çalıştırılamadı — hiçbir sözdizimi bulgusu iddia edilmedi. (2) assets/js (nicepay.js 403, nicepay-blocks.js, nicepay-admin.js, nicepay-shortcode-admin.js) — görev PHP mimarisiyle sınırlandığı için yalnızca `nicepayShortcodeAdmin.enabledMethods` sözleşmesini doğrulamak amacıyla noktasal okundu (sözleşme tutarlı, bulgu yok). (3) tests/ dizini yalnızca üretim iddialarını doğrulamak için okundu (wp-stubs.php'nin hangi fonksiyonları tanımladığı), test kalitesi ayrı bir boyut. (4) docs/analysis/*.md (14.700 satır) okunmadı — bunlar önceki analiz raporları ve görev tanımında 'kanıt değil' olarak işaretlenmiş; yalnızca README.md/readme.txt/CHANGELOG.md kod-doküman uyumu için tarandı. (5) languages/*.po/.mo dosyaları içerik olarak incelenmedi; ARCH-005'teki i18n bulgusu .pot çıkarım mekanizmasına dayanıyor, .po içeriğine değil.

**Açık sorular**

- `config_fingerprint` sütunu üç yerde yazılıp hiç okunmuyor ve iki yazıcı farklı formül kullanıyor (offer-resolver:157 commercial-array hash'i, gateway:276 [order_id, amount, currency, enabled_methods] hash'i). Bu, tamamlanmamış bir 'konfigürasyon kayması tespiti' özelliğinin kalıntısı mı, yoksa yalnızca adli inceleme için mi tutuluyor? Eğer adli amaçlıysa iki farklı formülün ne anlama geldiği hiçbir yerde belgelenmemiş.
- `NicePay_Retention::purge_batch()` (retention:205-249) `START TRANSACTION`/`COMMIT`/`ROLLBACK` kullanıyor, ancak tabloların InnoDB olduğu hiçbir yerde doğrulanmıyor — `create_table_sql()` motor belirtmiyor ve `$wpdb->get_charset_collate()` yalnızca karakter kümesi ekliyor. MyISAM varsayılanlı eski bir MySQL kurulumunda `ROLLBACK` sessizce hiçbir şey yapmaz ve refund_attempts silinip transactions silinmezse yetim audit kayıtları oluşur. Bu senaryo bilinçli olarak kabul edilmiş bir risk mi?
- `nicepay_check_public_rate_limit()` (functions:294-298) `REMOTE_ADDR` geçersizse fail-open davranıyor ve yorumda bunun 'tek paylaşılan global reddetme anahtarı' yaratmamak için olduğu belirtiliyor. Bir ödeme başlatma uç noktası için bu doğru takas mı, yoksa bu durumda istek reddedilmeli mi? Kararı operatöre bırakan bir filtre eklenmesi düşünüldü mü?
- `waiting` ve `cancelled` statüleri hiçbir kod yolunda yazılmıyor ama admin filtre listesinde ve etiket tablosunda duruyorlar. Bunlar 2.0.0 öncesi sürümlerden yükseltilen kurulumlardaki mevcut satırları desteklemek için mi tutuluyor? Öyleyse `class-nicepay-installer.php::scrub_legacy_sensitive_data()` gibi bir yerde bu eski statülerin nasıl ele alınacağı belgelenmeli.
- Şema sürümü `2026.08.24.7` plugin sürümünden bağımsız tutulmuş (iyi bir karar), ancak `nicepay_db_version` option'ı da `NICEPAY_VERSION` ile ayrıca yazılıyor (nicepay-payment-gateway.php:283) ve hiçbir yerde okunmuyor. İki ayrı sürüm option'ının bir arada bulunması bilinçli mi, yoksa `nicepay_db_version` da mı ölü?

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 21 |

## Öncelikli aksiyon listesi

1. **ARCH-002** — Şablon-metot (template method) ile ortak gövdeyi tek sınıfa taşı:
```php
abstract class NicePay_Auth_Return_Processor {
    protected $api;
    abstract protected function flow();                    // 'woocommerce' | 'standalone'
    abstract protected function on_reject( $error_code );
    abstract protected function on_abort( array $abort );
    abstract protected function on_success( $transact
2. **ARCH-011** — 1. Üretim kodundan `function_exists()` korumalarını kaldır ve çekirdek fonksiyonları doğrudan çağır:
```php
if ( ! is_ssl() ) {
    wp_send_json_error( array( 'message' => __( 'NicePay payments require HTTPS.', ... ) ), 503 );
    return;
}
```
2. Test tarafını düzelt: eksik stub'ları `tests/bootstrap/wp-stubs.php` içine ekle —
```php
if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() { 
