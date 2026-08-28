# NicePay düzeltme programı — uygulama ve kapanış durumu

> [!WARNING]
> **Donmuş tarihsel kayıt.** Bu durum tablosu `5855db1` tabanından başlayan 2026-08-20 anlık görüntüsüdür; güncel kodun kapanış defteri veya sevkiyat kapısı değildir. Güncel kararlar için `docs/analysis/pr-review2/` ve mevcut testler kullanılmalıdır.

**Son güncelleme:** 2026-08-20
**Çalışma dalı:** `development`
**Başlangıç tabanı:** `5855db1ac0fffe7c98e0c354158d9071fc32d420`
**Durum:** Değişiklikler çalışma ağacında; commit veya push yapılmadı.

Bu belge [13-remediation-action-plan.md](13-remediation-action-plan.md) içindeki uygulama
paketlerinin güncel durumunu gösterir. Analizdeki 484 numaralı kayıt/489 doğrulanmış bulgu,
aynı kök nedenleri farklı açılardan tekrar ettiği için paket bazında ele alınmıştır. Bir testin
yeşil olması tek başına bulgunun kapandığı anlamına gelmez; dış protokol veya ürün kararı
gerektiren maddeler ayrıca işaretlenmiştir.

## Durumların anlamı

| Durum | Anlamı |
|---|---|
| `DÜZELTİLDİ` | Kod değişikliği ve tekrarlanabilir regresyon kanıtı mevcut. |
| `KISMİ` | Güvenli kod tarafı tamamlandı; kabul ölçütünün bir bölümü gerçek WooCommerce, tarayıcı veya NICEPAY kanıtı bekliyor. |
| `DIŞ BAĞIMLI` | Güvenli biçimde varsayılamayacak sağlayıcı/işletme kararı gerekiyor; ilgili özellik kapalı ve belgelerde destekleniyor diye sunulmuyor. |
| `PLANLANDI` | Üretim güvenliği için zorunlu olmayan, ayrı ürün/sertifikasyon işi. |

## Kritik 14 bulgunun durumu

| Bulgu | Durum | Kanıt ve sınır |
|---|---|---|
| PROTOCOL-01 | `KISMİ` | Saklanan işlem, sipariş, tutar, MID, yöntem ve onay cevabı bağlanıyor; yanlış bağ net-cancel/reconciliation ile kesiliyor. İlk auth imzası `Moid` içermediği için kesin kapanış, doğrulanmış `ReqReserved` echo sözleşmesi gerektiriyor. |
| PROTOCOL-02 | `DÜZELTİLDİ` | Standalone imza yalnız sunucudaki kayıtlı tekliften üretiliyor; tarayıcı tutarı otorite değil. |
| FLOW-01 | `DÜZELTİLDİ` | Onaylanan tutar saklanan işlem ve güncel WC sipariş toplamıyla karşılaştırılıyor. Sıfır dolgulu protokol tutarları ayrı normalize ediliyor. |
| FLOW-02 | `KISMİ` | Çok alanlı ve akışa özel bağlama mevcut; ilk auth mesajındaki `Moid` bütünlüğü PROTOCOL-01 ile aynı dış kanıta bağlı. |
| FLOW-03 | `DÜZELTİLDİ` | İstemcinin keyfi tutar için geçerli imza üretmesi engellendi. |
| SECURITY-01 | `KISMİ` | Ucuz siparişi pahalı siparişe başarılı şekilde yerleştirme yolu kapalı; imzalı ilk bağlama için sağlayıcı echo fixture'ı gerekiyor. |
| UX-001 | `DIŞ BAĞIMLI` | Eksik VBANK yaşam döngüsü nedeniyle yöntem bütünüyle devre dışı ve destek iddialarından çıkarıldı. Gerçek deposit-notification sözleşmesi olmadan etkinleştirilmeyecek. |
| UX-002 | `DÜZELTİLDİ` | Standalone ticari alanlar sunucu otoritesinde; istemci yalnız alıcı girdilerini verir. |
| PLATFORM-01 | `KISMİ` | Blocks sunucu adaptörü ve istemci kaydı eklendi, birim testi var. Gerçek WooCommerce Cart/Checkout Blocks matrisi geçmeden uyumluluk beyanı yapılmadı. |
| PLATFORM-02 | `DÜZELTİLDİ` | Sunucu otoriteli teklif çözümleyici ve negatif manipülasyon testleri mevcut. |
| CODE-01 | `DÜZELTİLDİ` | Teklif çözümleme, inbound doğrulama ve işlem deposu ayrıştırıldı. |
| TESTS-01 | `DÜZELTİLDİ` | Tutar/MID/yöntem/akış/sipariş bağlama regresyonları PHP 7.4–8.3 matrisinde çalışıyor. |
| EXCELLENCE-01 | `DÜZELTİLDİ` | `TxTid` ve auth bağlamı onay öncesi saklanıyor; belirsiz sonuç `needs_reconciliation` oluyor. |
| EXCELLENCE-02 | `DÜZELTİLDİ` | Refund isteği ağ çağrısından önce ayrı audit satırına yazılıyor; CAS, bilinmeyen sonuç ve yerel kayıt hatası görünür. |

Kritik bulgularda güvenli kod kapanışı: **9 düzeltildi, 4 kısmi, 1 dış bağımlı**.
Kısmi/dış bağımlı maddelerin hiçbiri canlı destek iddiası veya sessiz fail-open davranışı üretmiyor.

## Uygulama paketleri

| Paket | Durum | Tamamlanan ana iş | Kalan kabul ölçütü |
|---|---|---|---|
| R01 Protokol/golden fixture | `KISMİ` | Sekiz mevcut imza formülü korundu; literal imza, padded amount ve fail-closed testleri eklendi. | Legacy protokol desteği, resmi auth/cancel/net-cancel fixture'ları ve `ReqReserved` echo kanıtı. |
| R02 Şema/depo/veri yaşamı | `KISMİ` | Sürümlü, idempotent migration; allowlist/format haritaları; benzersiz Moid/TID/aktif deneme; refund audit; scrub; gerçek MariaDB fresh/upgrade/repair/EXPLAIN testi. | Finansal saklama süresi işletme kararı. |
| R03 Sunucu otoriteli başlangıç | `DÜZELTİLDİ` | Kayıtlı sabit teklif, KRW, yöntem/credential/readiness kontrolü, anlık EdiDate/Moid/imza, oran sınırlama ve bayt sınırları. | — |
| R04 Ortak inbound bağlama | `KISMİ` | MID, Moid, tutar, yöntem, akış, para birimi, WC snapshot ve onay cevabı doğrulaması. | İlk auth için imzalı context/echo kanıtı. |
| R05 Atomik durum makinesi | `DÜZELTİLDİ` | Sipariş başına benzersiz aktif deneme, sibling abandon + atomik claim, monotonic durumlar, replay ve stale approval escalation. | — |
| R06 HTTP/net-cancel | `KISMİ` | HTTPS host/path/port allowlist, redirect kapalı, 2xx/JSON/alan/imza kontrolleri, istek-kapsamlı 5/30 sn timeout, kalıcı net-cancel/reconciliation audit ve log redaksiyonu. | Gerçek sağlayıcı timeout/retry fixture'ları. |
| R07 Refund ledger | `KISMİ` | Tek WC refund yolu, bakiye CAS'i, benzersiz cancel Moid, attempt geçmişi, tutar/TID bağlama, kalan bakiye ve capability flag'leri. | Gerçek tam/kısmi refund fixture'ları; sertifikasız mobil/wallet kısmi refundlar kapalı. |
| R08 Güvenli ayarlar | `DÜZELTİLDİ` | Gateway varsayılan kapalı, bilinmeyen mod fail-closed, secret yeniden gösterilmiyor, yöntemler tek registry, readiness/test-live uyarıları ve doğru yetkiler. | — |
| R09 VBANK | `DIŞ BAĞIMLI` | Tam özellik devre dışı, yanlış destek iddiaları kaldırıldı. | Deposit notification, imza, retry, expiry ve refund sözleşmesi + sandbox sertifikası. |
| R10 Blocks/HPOS/routing/multisite | `KISMİ` | Blocks adaptörü, plain/pretty return helper, multisite migration, HTTPS/no-store; gerçek WC 11.0.1 üzerinde legacy+HPOS order CRUD ve Blocks kayıt/aktiflik smoke testi. Başarılı depolama matrisi sonrasında HPOS uyumluluğu WooCommerce'in resmi API'siyle beyan edildi ve aynı gerçek entegrasyon testinde doğrulandı. | Tarayıcıdan gerçek Checkout Blocks, minimum WC sürümü ve multisite matrisi; Cart/Checkout Blocks uyumluluk beyanı bu kanıtlar tamamlanana kadar bilinçli olarak yok. |
| R11 Entegrasyon testleri | `KISMİ` | Para yolu birim testleri, PHP 7.4–8.3, JS DOM, CSS parser, release smoke, gerçek WP/MariaDB migration ve WC legacy+HPOS gate'leri. | Tarayıcı Checkout ve sanitize edilmiş NICEPAY sandbox E2E matrisi. |
| R12 Ledger/reconciliation/privacy | `KISMİ` | Reconciliation sayımı/durumları, güvenli işlem ayrıntısı, refund geçmişi, test/live ve ödeme özeti, indeksli liste, para birimine göre filtreli finansal toplamlar, formül enjeksiyonuna dayanıklı sınırlı CSV, HPOS uyumlu sipariş ledger özeti ve exporter/eraser. | Çok büyük veri hacminde arama/yöntem filtrelerinin sorgu planı ve işletme saklama politikası. |
| R13 Readiness/onboarding | `KISMİ` | Site geneli test/live, eksik canlı credential ve geçersiz mod uyarıları; secret içermeyen kopyalanabilir sistem raporu. | Canlı health probe ve gerçek sandbox'a bağlı güvenli test ödeme. |
| R14 Standalone receipt/recovery | `KISMİ` | Hash'li bearer receipt, kalıcı sonuç, güvenli mesaj, retry yolu ve receipt e-postası. | Gerçek mobil/cross-site tarayıcı ve e-posta teslimat matrisi. |
| R15 Shortcode/JavaScript | `DÜZELTİLDİ` | Opaque ID, referans shortcode, server-side policy, çoklu instance ödeme JS'i; generator ve refund modalı ayrı sürümlü asset'lerde; özel buton sınıfı temel davranış sınıfını artık düşürmüyor; jsdom regresyonları. | — |
| R16 Erişilebilirlik/görsel sistem | `KISMİ` | Dialog semantiği, focus trap/restore, alan-hata ilişkisi, reduced motion, CSS syntax, çoklu form odağı ve özel buton arka planına göre otomatik yüksek-kontrast metin rengi. | Ekran okuyucu, Safari/Firefox/iOS, işletim sistemi yüksek kontrast modu ve Türkçe genişleme manuel matrisi. |
| R17 UTF-8/KST/i18n | `KISMİ` | UTF-8 zorunlu, KST timestamp, güvenli result-code mesajları, POT/PO/MO yeniden üretimi ve compile kontrolü. | Dört dilde kalan fuzzy/çevrilmemiş girdilerin insan çevirisi ve proofreading. |
| R18 CI/release/governance | `KISMİ` | Kilitli bağımlılıklar, audit, PHP/JS/CSS/test/DB/build gate'leri, deterministic allowlist ZIP, checksum, SECURITY/CODEOWNERS/Dependabot/templates ve `Update URI`. | PHPCS/PHPStan borcunun kademeli kapatılması, gerçek WP/WC matrisi ve otomatik güncelleme kanalı kararı. |
| R19 Dokümantasyon | `KISMİ` | Desteklenen/desteklenmeyen yüzeyler, güvenli akışlar, timeout/privacy/refund/cache/HTTPS ve paket içi linkler güncellendi. | Kalan düşük önemdeki yapı/extension-surface/link denetimi. |
| R20–R24 Ürün genişletmeleri | `PLANLANDI` / `DIŞ BAĞIMLI` | Yanlış Subscription/VBANK/escrow/tax/open-amount iddiaları ve güvensiz girişler kaldırıldı/devre dışı. | Payment links, wallet/installment, vergi/escrow, recurring ve mutation projeleri ayrı ürün, sağlayıcı ve uyum kararları gerektirir. |

## Tekrarlanabilir doğrulama kanıtı

- PHP 7.4, 8.0, 8.1, 8.2 ve 8.3: her sürümde **387 test / 1074 assertion**, başarılı.
- PHP 7.4 ve 8.3 lint: **39 PHP dosyası**, başarılı.
- Gerçek WordPress + MariaDB 10.11: fresh install, `.5 -> .6` additive migration,
  legacy hassas veri scrub, eksik tablo onarımı ve `idx_created_at` EXPLAIN, başarılı.
- Gerçek WooCommerce 11.0.1: gateway/default/process-payment/repository/Blocks smoke,
  gerçek `wc_create_refund()` + imzalı yerel cancel cevabı ve legacy + HPOS sipariş CRUD
  yaşam döngüsü, başarılı.
- ESLint, CSS parser ve beş jsdom ödeme/generator/refund-modal testi, başarılı.
- Composer strict validation ve iki bağımlılık audit'i: advisory yok.
- GitHub Actions workflow lint, sürüm tutarlılığı, dört PO için `msgfmt` ve
  `git diff --check`, başarılı.
- Gerçek allowlist release ZIP build ve artifact smoke testi, başarılı.

## Canlı yayın öncesi gerçek engeller

1. **NICEPAY sözleşmesi ve sandbox:** Merchant MID'in legacy PG-Web v3/manual v2.0.8 için
   provision edildiği doğrulanmalı; sanitize edilmiş auth/approval/cancel/net-cancel fixture'ları
   alınmalı. `Moid` için imzalı context ancak echo davranışı kanıtlandıktan sonra etkinleştirilebilir.
2. **Gerçek WooCommerce matrisi:** HPOS açık/kapalı sipariş depolama matrisi geçtiği için HPOS
   uyumluluğu beyan edilmiştir. Classic + Blocks checkout, pretty/plain permalink,
   single/multisite ve mobil dönüş matrisi henüz gerçek tarayıcıda tamamlanmadığından Cart/Checkout
   Blocks uyumluluk beyanı eklenmemiştir.
3. **Ürün/veri kararları:** Finansal kayıt saklama süresi, otomatik güncelleme dağıtım kanalı,
   açık tutar, VBANK ve opsiyonel Kore ödeme özellikleri sahibi tarafından kararlaştırılmalıdır.
4. **İnsan doğrulaması:** Eksik çeviriler, ekran okuyucu/tarayıcı matrisi ve sağlayıcı popup akışı
   otomasyonla doğruymuş gibi ilan edilemez.

Bu engeller çözülmeden canlı gateway'i varsayılan olarak açmak, VBANK/recurring/escrow/tax/open
amount özelliklerini sunmak veya Cart/Checkout Blocks uyumluluğu ilan etmek güvenli değildir.
