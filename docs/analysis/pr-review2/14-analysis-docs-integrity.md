# 14 — docs/analysis Bütünlüğü: İddia vs Gerçek

> PR #3 sistematik review · düşmanca doğrulamalı · 15 bulgu

## Özet

15 analiz dokümanının kendi içindeki mühendislik disiplini gerçekten yüksek: 01-findings-index.md'deki 484 satır tam sayılıyor (14/66/246/135/23), hiçbir ID ailesinde boşluk veya çift kayıt yok, tek eksik olan UX-117 zaten dipnotta açıklanmış, index'teki her ID hedef dokümanda mevcut ve 500+ iç bağlantıdan yalnız 1'i kırık. 13/14'teki "DÜZELTİLDİ" iddialarını koda karşı örnekleyerek doğruladım — atomik claim, sunucu otoriteli teklif, opak shortcode ID'leri (cfg_+16 hex), EUC-KR'nin tamamen kaldırılması, gateway varsayılan 'no', secret'ın HTML'e basılmaması, KRW yuvarlama, CSV formül enjeksiyonu koruması, 500 kayıt/gün sınırlı retention — hepsi gerçekten uygulanmış. "Kapatıldı denip düzeltilmemiş" sınıfı bu PR'da neredeyse hiç görülmüyor ve bu övgüyü hak ediyor. Asıl sorun dokümanların İÇERİĞİ değil, MERGE EDİLME BİÇİMİ: 00–12 arası 11 doküman 5855db1 taban commit'ini şimdiki zamanda anlatıyor, hiçbirinde tarih/sahip/geçerlilik damgası ya da "bu artık geçersizdir" bandı yok, 2.373 adet #L satır bağlantısı artık alakasız koda düşüyor ve 04-security.md içinde çalışır bir curl sömürü komutu hâlâ 2.0.0 olarak numaralandırılmış bir sürüme karşı herkese açık yayımlanıyor. Üstüne 13-remediation-action-plan.md'nin kendi zorunlu kabul artefaktı (bulgu-başına closure ledger) hiç üretilmemiş ve planın kendi P0 kapısı (R01–R08, R10–R11 tamamlanmış olmalı) doküman 14'e göre 10 paketten 7'sinde sağlanmıyor — buna rağmen PR "release readiness" başlığıyla geliyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 4 |
| 🟡 Orta | 8 |
| 🔵 Düşük | 3 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 4 |
| Bağımsız doğrulama kararı alan bulgu | 6 / 15 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 10 |

## Güçlü yönler

- 01-findings-index.md aritmetiği kusursuz: 484 satır = 14+66+246+135+23; her prefiks (PROTOCOL 34, FLOW 42, SECURITY 28, UX 125, PLATFORM 42, CODE 55, I18N 21, TESTS 37, DOCS 39, RELEASE 21, EXCELLENCE 40) 1..N aralığında boşluksuz ve tekrarsız; tek eksik UX-117, 00-executive-summary.md'de 'UX-099 ile aynı girdiyi paylaşıyor' diye açıkça belirtilmiş.
- Index'te listelenen 484 ID'nin tamamı, gösterdiği detay dokümanında gerçekten mevcut (otomatik kontrol: 0 eksik).
- Markdown çapa (anchor) hijyeni beklenenden çok iyi: 10-documentation.md ve 12-path-to-first-class.md kendi <a id="docs-001">/<a id="excellence-40"> çapalarını elle tanımlamış; 500+ iç bağlantıdan yalnızca 1 tanesi (01-findings-index.md:554 -> 09-testing.md TESTS-36) çözülmüyor.
- docs/analysis dağıtım paketinden ÜÇ ayrı yerde dışlanıyor: .gitattributes:27 export-ignore, .github/scripts/build-release.sh:41-64 allowlist kopyalama, ve iki CI kapısı (.github/scripts/smoke-check-artifact.sh:50, deploy-wordpress-org.yml:177). Yani WP.org ZIP'ine kesinlikle girmiyor.
- 13-remediation-action-plan.md paket başına açık 'Acceptance gate' tanımlıyor (ör. R04: 'A valid cheap-payment callback with another order's Moid causes zero approval calls and no order mutation') — ölçülebilir, test edilebilir kriterler.
- 14-remediation-progress.md'nin durum sözlüğü (DÜZELTİLDİ/KISMİ/DIŞ BAĞIMLI/PLANLANDI) dürüst tanımlanmış ve 'Bir testin yeşil olması tek başına bulgunun kapandığı anlamına gelmez' uyarısı doğru bir epistemik duruş.
- Kritik 14 bulgunun durum tablosu aritmetiği tutuyor (9 düzeltildi + 4 kısmi + 1 dış bağımlı = 14) ve iddia edilen kapanışlar kodda doğrulanabiliyor: atomik CAS claim (nicepay-functions.php:653-709), sunucu otoriteli offer resolver, EUC-KR'nin tamamen silinmesi (grep: 0 eşleşme), gateway 'enabled' default 'no' (class-nicepay-gateway.php:45), merchant key'in input value'suna basılmaması (class-nicepay-admin.php:660,683), KRW half-up yuvarlama (nicepay-functions.php:1513-1515), opak shortcode ID'leri (nicepay-payment-gateway.php:743).
- 13-remediation-action-plan.md §8 'Claims and recommendations that must not become fixes' bölümü, kendi analizinin yanlış tavsiyelerine karşı bir koruma katmanı kuruyor — analiz raporlarında nadir görülen olgun bir pratik.
- 13-remediation-action-plan.md:22-28 'Verified starting state' bölümü baseline test sayısını (129 test/170 assertion) ve phpunit.xml'in ödeme sınıflarını coverage dışı bıraktığını açıkça kaydediyor; ilerleme iddiaları bu tabana göre kıyaslanabilir kılınmış.
- PR açıklamasındaki altyapı iddiaları doğrulandı: WP.org workflow yalnız workflow_dispatch ve mode varsayılanı 'dry-run' (deploy-wordpress-org.yml:4,17); MariaDB 10.11 ve WooCommerce 11.0.1 gerçekten pinlenmiş (run-schema-migration.sh:11, run-woocommerce-smoke.sh:11,14); Checkout Blocks uyumluluğu gerçekten beyan edilmemiş (yalnız custom_order_tables — nicepay-payment-gateway.php:43-44).

## Bulgular

### META-001 — 00–12 arası 11 doküman, düzeltilmiş kodun yanına "şimdiki zaman" kipiyle ve hiçbir geçersizlik bandı olmadan merge ediliyor; 2.373 satır referansı alakasız koda düşüyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek *(doğrulama sonrası; ilk değer 🔴 Kritik)* |
| **Kategori** | stale-documentation |
| **Konum** | [docs/analysis/00-executive-summary.md:38](../../../docs/analysis/00-executive-summary.md#L38) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
00-executive-summary.md:38: "**This plugin is not production-ready, and three specific defects are what block it.** ... the gateway ships `enabled => 'yes'` pre-seeded with NICEPAY's *published* sandbox credentials". Bu ifade `5855db1` tabanı için doğruydu; PR sonrası includes/class-nicepay-gateway.php:45 `'default' => 'no'`. Aynı doküman ailesinde: 07-architecture-and-code-quality.md:3 "ten production PHP files (3,863 LOC)" — güncel ağaçta 17 üretim PHP dosyası / 9.100 satır; 09-testing.md:3 "ships 98 PHPUnit test methods across four files ... those 98 tests reach 24 of 86 production functions" — güncel ağaçta 20 dosyada 264 test metodu. Baseline damgası yalnızca 00-executive-summary.md:215 dipnotunda ve 01-findings-index.md:20 blockquote'unda saklı; 02–12 arasındaki 9 dokümanın hiçbirinde tarih, taban commit, sahip veya geçerlilik notu YOK (grep 'superseded|outdated|historical|snapshot of' → yalnız alakasız gövde metni eşleşmeleri). Ayrıca dokümanlar 2.373 adet `](../../<dosya>#L<satır>)` bağlantısı içeriyor (01: 476, 05: 148, 10: 112, 12: 98 ...). Bu bağlantılar dal-göreli olduğu için GitHub'da GÜNCEL branch'e çözülüyor: 04-security.md:87 SECURITY-01 kanıtı olarak includes/class-nicepay-gateway.php:226-234'ü gösteriyor; o satırlar bugün goods_name birleştirme kodu (`$goods_name = $first_item->get_name();`).
```

**Başarısızlık senaryosu**

PR merge edilir. İki hafta sonra bir güvenlik denetçisi (veya WordPress.org plugin review ekibi) repoyu inceler, docs/analysis/00-executive-summary.md:38'i okur ve "eklenti aktive edildiğinde canlı checkout'a demo kimlik bilgileriyle çalışan bir ödeme yöntemi koyuyor" sonucuna varır. Kanıt bağlantısına tıklar (includes/class-nicepay-gateway.php#L41), tamamen ilgisiz koda düşer, dokümanın mı kodun mu doğru olduğunu ayırt edemez. Sonuç: ya yayın bloke edilir ya da (daha kötüsü) doküman "eski" varsayılıp içindeki HÂLÂ geçerli olan KISMİ maddeler de göz ardı edilir.

**Etki**

main'e merge edildikten sonra deponun en büyük doküman kütlesi (16.347 satır), ürünün mevcut hali hakkında iddialı ve YANLIŞ ifadeler barındırıyor. Bir denetçi, yeni bir geliştirici veya bir tacir docs/analysis/04-security.md'yi açtığında "canlı para almamalı" hükmünü ve 14 kritik bulguyu okuyor; hiçbir yerde bunların düzeltilmiş bir taban için yazıldığı yazmıyor. Kanıt bağlantıları da onu doğrulamıyor, çünkü hepsi kayık. Bu, dokümantasyonun aktif zarar verdiği klasik durum: en güvenilir görünen artefakt en yanıltıcı olan.

**Öneri**

İki adımdan birini seç: (a) 00–12'yi PR'dan çıkar ve ayrı bir `analysis/` deposunda veya bir GitHub Discussion/Wiki sayfasında tut; ya da (b) her dokümanın İLK satırına makine-üretilebilir bir band ekle ve CI'da varlığını zorunlu kıl. Örnek band:

```markdown
> **ARŞİV — GEÇERSİZ ANALİZ.** Bu doküman `5855db1` (2026-08-19) taban ağacını anlatır.
> Bulguların çoğu PR #3 ile kapatılmıştır; güncel durum için
> `[14-remediation-progress.md](14-remediation-progress.md)` tek yetkili kaynaktır.
> Aşağıdaki tüm `file:line` referansları taban commit'e aittir ve bugünkü ağaçla eşleşmez.
```

Ayrıca tüm `](../../x.php#L123)` bağlantılarını taban commit'e sabitle (`](https://github.com/<org>/<repo>/blob/5855db1/includes/...#L226)`) — bir sed ile tek seferde yapılabilir — ve `.github/scripts/check-docs-banner.sh` ile bandın her 00–12 dosyasında bulunmasını CI kapısı yap.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 00–12 arası 13 doküman (16.347 satırın 15.560'ı), PR #3 ile düzeltilmiş kodun yanına hiçbir geçersizlik bandı olmadan ve "şimdiki zaman" kipiyle merge ediliyor. Taban commit damgası yalnızca 00-executive-summary.md:215 dipnotunda geçiyor; 01-findings-index.md:20 sadece "branch `main`" diyip commit vermiyor; 02–12'nin hiçbirinde tarih, taban commit, sahip veya geçerlilik notu yok. İçerik ölçülebilir biçimde eski: 00:38 "ships `enabled => 'yes'`" iddiası gateway.php:45'te `'default' => 'no'` ile çürütülüyor; 07:3 "ten production PHP files (3,863 LOC)" gerçekte 17 dosya / 9.100 satır; 09:3 "98 test methods across four files" gerçekte 20 dosyada 264 metot. Ayrıca 2.373 adet dal-göreli `](../../<dosya>#L<satır>)` bağlantısı GitHub'da GÜNCEL branch'e çözülüyor ve kayıyor — 04-security.md:87 SECURITY-01 kanıtı olarak gateway.php:226'yı gösteriyor, orada bugün goods_name birleştirme kodu var. (İddiadaki dosya-başı bağlantı kırılımı yanlıştı; doğru ham dağılım 01=476, 05=373, 04=220, 10=194, 07=170.) Ağırlaştırıcı olarak: güncel durumu doğru anlatan 13/14 numaralı dokümanlar mevcut ama 00–12'den onlara giden tek bir bağlantı yok ve dil farklı (13/14 Türkçe, 00–12 İngilizce), README/CHANGELOG/CONTRIBUTING'de de docs/analysis'e hiçbir atıf veya çekince yok.
- Gerekçe: Her yük taşıyan iddiayı dosyadan okuyarak doğruladım; çekirdek bulgu ayakta. Sadece bir detay (bağlantı sayılarının dosya kırılımı) yanlış ve severity'nin "critical" olması bu depodaki diğer critical'lara göre şişkin.

DOĞRULANANLAR (hepsi birebir):
1. Konum doğru: 00-executive-summary.md:38 gerçekten "**This plugin is not production-ready...**" cümlesi ve "the gateway ships `enabled => 'yes'` pre-seeded with NICEPAY's *published* sandbox credentials" ifadesini içeriyor. Güncel kodda includes/class-nicepay-gateway.php:45 `'default' => 'no'` — doküman düzeltilmiş kod hakkında yanlış konuşuyor.
2. 07-architecture-and-code-quality.md:3 "ten production PHP files (3,863 LOC)". Ben saydım: vendor/tests/node_modules hariç 17 üretim PHP dosyası, toplam TAM 9.100 satır. İddia edilen rakamla birebir örtüşüyor.
3. 09-testing.md:3 "98 PHPUnit test methods across four files ... those 98 tests reach **24 of 86 production functions**". Ben saydım: tests/ altında 20 unit test dosyası (25 PHP dosyası toplam), `grep -rhoE 'function test_[A-Za-z0-9_]+' tests` → 264 test metodu. Doküman 2,7 kat eski.
4. Bağlantı toplamı doğru: `grep -rhoE '\]\(\.\./\.\./[^)]*#L[0-9]+\)' *.md | wc -l` → **2373**, iddia edilen sayıyla birebir aynı. Bu bağlantılar dal-göreli (`../../includes/...#L226`), yani GitHub'da güncel branch'e çözülüyor.
5. Kayık bağlantı kanıtı doğru: 04-security.md:87 SECURITY-01 için "**Files:** `[includes/class-nicepay-gateway.php:226-234](../../includes/class-nicepay-gateway.php#L226)`" diyor; güncel gateway.php:226 `$goods_name = $first_item->get_name();` yani goods_name birleştirme kodu. Tamamen alakasız.
6. Banner yokluğu doğru: `grep -rniE 'superseded|outdated|historical|snapshot of|ARCHIVE|baseline|PR #3|arşiv|5855db1'` 02–12 üzerinde çalıştırdım; yalnızca alakasız gövde eşleşmeleri döndü (02:21 "archived verbatim", 03:787 "superseded one", 08:652 "superseded by", 11:267 "on 2026-08-19"). 02–12'nin hiçbirinin ilk satırlarında tarih/taban/geçerlilik notu yok.
7. Taban damgası gerçekten sadece iki yerde: 00-executive-summary.md:215 "*Review conducted against branch `main`, working tree clean, at commit `5855db1`*" ve 01-findings-index.md:20 blockquote "Every code quotation was read from the working tree at branch `main`" (bu ikincisi commit bile vermiyor).
8. Dokümanlar gerçekten bu PR'da yeni: `git diff --numstat origin/main...development -- docs/analysis` → 15 dosyanın hepsi salt ekleme (215/0, 622/0, ..., 100/0), toplam 16.347 satır. `git ls-tree origin/main -- docs/` sadece API-REFERENCE/ARCHITECTURE/CONFIGURATION/DEVELOPER-GUIDE/USER-GUIDE gösteriyor; docs/analysis main'de YOK.

AĞIRLAŞTIRICI (iddiada olmayan, kendim buldum):
- 13-remediation-action-plan.md ve 14-remediation-progress.md güncel durumu doğru anlatıyor (14:3 "Son güncelleme: 2026-08-20", 14:5 taban `5855db1ac0...`, 14:27-40 kritik 14 bulgunun DÜZELTİLDİ/KISMİ durumu) — ama `grep -rn '14-remediation' 0*.md 1[0-2]*.md` HİÇBİR eşleşme vermiyor. Yani 00–12'den güncel duruma giden tek bir yol bile yok. Üstelik 13/14 Türkçe, 00–12 İngilizce; bir denetçi için köprü fiilen mevcut değil.
- README.md, CHANGELOG.md, CONTRIBUTING.md ve docs/*.md içinde `docs/analysis` geçmiyor — dışarıdan gelen bir çekince notu da yok.

DÜZELTİLEN DETAY:
İddiadaki dosya kırılımı ("01: 476, 05: 148, 10: 112, 12: 98") 4 rakamdan 3'ü yanlış. Gerçek ham sayımlar: 01=476 (doğru), 05=373, 10=194, 12=103; 04=220, 07=170, 03=168, 02=157. Tekil (dedupe) sayımlar ise 01=337, 05=318, 10=166, 12=72. Yani iddia sahibi ne ham ne tekil sayımla tutarlı bir alt küme vermiş — toplam 2373 doğru olsa da kırılım uydurulmuş/karışmış görünüyor.

DÜZELTİLEN SENARYO DETAYI:
Başarısızlık senaryosu "includes/class-nicepay-gateway.php#L41'e tıklar, tamamen ilgisiz koda düşer" diyor. Aslında 00-executive-summary.md:55'teki bağlantı `#L41` ve güncel L41-46 HÂLÂ `'enabled'` form alanı — ilgisiz değil, tam aksine dokümanı ÇÜRÜTEN kod (L45 `'default' => 'no'`). Bu senaryoyu zayıflatmıyor, değiştiriyor: okur "ilgisiz kod" değil, "doküman açıkça yalan söylüyor" görüyor. Gerçek "alakasız koda düşme" örneği 04-security.md:87 → gateway.php:226 (goods_name), ki o doğru.

SEVERITY:
`critical` savunulabilir değil. Çalışan hiçbir kod yolunu, para akışını veya veriyi etkilemiyor; düzeltmesi bir sed + kısa CI kontrolü; ve aynı dizinde (bağlantısız da olsa) yetkili bir güncel-durum kaynağı mevcut. Aynı incelemedeki "ucuz ödeme pahalı siparişi kapatır" sınıfı bulgularla eşdeğer sayılamaz. `high` doğru seviye: yayın/denetim akışını gerçekten bloke edebilir ve deponun en büyük artefaktı aktif olarak yanlış bilgi veriyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Docs 00–12 (16,347 lines, all added by this PR) describe the pre-PR baseline `5855db1` in present tense while sitting next to the fixed code, and carry no invalidity banner in any of 01–12 (baseline commit appears only in 00's closing footnote and in 13/14). Their 2,373 branch-relative `#L` evidence links resolve against post-merge `main` and no longer match the cited code. Additionally — not stated in the original claim — none of 00–12 forward-references 13/14, and `docs/analysis/` has no README index, so a reader has no in-repo path from a stale document to the current-state document. Blast radius is repo-facing only: `.github/workflows/deploy-wordpress-org.yml:177` and `.github/scripts/smoke-check-artifact.sh:50` both hard-fail if `docs/analysis` appears in the release zip, so end merchants and the WordPress.org review artifact never see these files. Affected readers are GitHub-repo visitors: auditors, contributors, and pre-install evaluators.
- Gerekçe: I tried to break this claim on four axes and it survived all four on facts; only the severity label and one actor in the failure scenario are overstated.

1) Is the "corrected code" claim real? YES. `includes/class-nicepay-gateway.php:45` is `'default' => 'no',` inside the `enabled` form field, while `docs/analysis/00-executive-summary.md:38` still asserts, in present tense, that "the gateway ships `enabled => 'yes'` pre-seeded with NICEPAY's *published* sandbox credentials". Direct contradiction between two files being merged in the same PR.

2) Are the quantitative drift claims real? YES, and the reviewer's numbers are exact, not approximate. `07-architecture-and-code-quality.md:3` says "ten production PHP files (3,863 LOC)"; `find . -name '*.php' -not -path './tests/*' ...` returns 17 files / 9,100 lines — the reviewer's exact figures. `09-testing.md:3` says "98 PHPUnit test methods across four files"; `grep -rhoE 'public function test[A-Za-z0-9_]*' tests/ | wc -l` = 264 across 20 files with test methods — again the reviewer's exact figures. So the docs understate the tree by 70% on files and 62% on tests.

3) Is the "no invalidity band" claim real? YES, and stronger than stated. `grep -c '5855db1' *.md` returns 0 for ALL of 01–12. The only baseline stamps in the whole set are `00-executive-summary.md:215` (a closing italic footnote: "*Review conducted against branch `main`, working tree clean, at commit `5855db1`.*") and 13/14, which carry proper headers (`**Baseline commit:** 5855db1ac0f...`). I additionally checked something the claim did not: `grep -ln '13-remediation\|14-remediation' 0*.md 1[0-2]*.md` returns NOTHING — not one of docs 00–12 forward-references the remediation-progress document that would tell a reader they are stale. There is also no `README.md` index inside `docs/analysis/`, and no CI gate on a banner. So a reader entering at 04-security.md has zero in-file signal and zero navigational escape hatch.

4) Are the 2,373 shifted links real? YES. `grep -oE '\]\(\.\./\.\./[^)]*#L[0-9]+\)' *.md | wc -l` = 2373 exactly, distributed 01:476, 05:373, 04:220, 10:194 etc. These are branch-relative, so on GitHub they resolve against whatever branch the reader is viewing — i.e. post-merge `main`. I dereferenced the specific example: `04-security.md:87` cites `class-nicepay-gateway.php:226-234` as evidence for SECURITY-01 (order binding), and `sed -n '222,236p'` on today's file shows lines 226–234 are the `goods_name` concatenation block (`$goods_name = $first_item->get_name();` at :226, `_n( ' and %d more item', ...)` at :231). Confirmed: the flagship critical finding's primary evidence link now lands on unrelated i18n string-building code.

WHERE I PUSH BACK — exploitability and severity. Applying the consequence lens honestly: there is no HTTP request, no role, no input, no timing. Nothing here can be *exploited*; it can only be *misread*. And the blast radius is narrower than the claim implies in one specific way the reviewer missed: `docs/analysis` is explicitly excluded from the shipped artifact — `.github/workflows/deploy-wordpress-org.yml:177` fails the build if the zip contains `docs/analysis`, and `.github/scripts/smoke-check-artifact.sh:50` asserts the same. So the failure scenario's "WordPress.org plugin review team" actor is largely refuted: that team reviews the submitted zip, which provably cannot contain these files. The realistic actors are the ones who browse the GitHub repo — a security auditor doing supply-chain diligence, a new contributor, a merchant evaluating the plugin on GitHub before install. That is a real and likely population, so the finding stands, but it is a repo-facing reputational/onboarding hazard rather than a shipping-product defect.

Severity `critical` is reserved in this review set for things that lose money or take live payments (SECURITY-01, the nopriv signing endpoint). This costs no money and has no attacker. It is a genuine and self-inflicted integrity problem — a 16k-line corpus whose headline verdict is now false, with 2,373 evidence links pointing at the wrong lines — but it belongs at `high`. The recommended fix (banner + pinned permalinks + CI gate) is correct and cheap; I would add that the banner alone is insufficient because pinning the links to `blob/5855db1/` is what actually restores the evidence, and that adding a forward reference to 14-remediation-progress.md in each of 00–12 costs one sed and closes the navigational dead-end I found.

---

### META-002 — Sömürü adımlarını ve çalışır bir curl komutunu içeren güvenlik analizi, düzeltilmiş sürüm de "2.0.0" kaldığı için yayımlanmış savunmasız sürüme karşı silah haline geliyor — ve bu, deponun kendi SECURITY.md politikasını ihlal ediyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek *(doğrulama sonrası; ilk değer 🔴 Kritik)* |
| **Kategori** | coordinated-disclosure |
| **Konum** | [docs/analysis/04-security.md:180](../../../docs/analysis/04-security.md#L180) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
04-security.md:180 tam bir saldırı reçetesi veriyor: "(1) Lift the shared anonymous nonce from any page carrying the shortcode. (2) `curl -X POST …/admin-ajax.php -d 'action=nicepay_init_payment&nonce=…&amount=100&goods_name=x&...'` and keep `sign_data` + `edi_date`. (3) Build a payment form with `Amt=100` ... (4) Intercept the auth response and POST it to `/?wc-api=nicepay_return` with `Moid` set to a victim order's Moid." 04-security.md:116 ve :288'de iki sömürü yolu daha var. Bu, `Version: 2.0.0`'a karşı yazıldı (git show origin/main:nicepay-payment-gateway.php → "Version: 2.0.0", CHANGELOG main'de "## [2.0.0] - 2026-04-03"). Düzeltilmiş build de AYNI sürüm: nicepay-payment-gateway.php:7 "Version: 2.0.0", :22 NICEPAY_VERSION '2.0.0', readme.txt:6 "Stable tag: 2.0.0". `git tag` çıktısı BOŞ — hiç sürüm etiketi yok, dolayısıyla iki 2.0.0'ı ayırt edecek hiçbir işaret yok. Buna karşılık SECURITY.md:9 "Please do not disclose suspected vulnerabilities in public issues, pull requests, logs, or screenshots" ve SECURITY.md:18 "Please allow time for merchants to update before publishing technical details" diyor. CHANGELOG.md:5 ayrıca yayımlanmış `[2.0.0] - 2026-04-03` girdisini `[2.0.0] - 2026-08-20` ile ÜZERİNE YAZIYOR — Nisan'da neyin çıktığının kaydı yok ediliyor, oysa dosya başlığı "All notable changes ... are documented in this file" diyor.
```

**Başarısızlık senaryosu**

Tacir A, Nisan 2026'da yayımlanan 2.0.0'ı canlı KRW mağazasında çalıştırıyor. PR #3 merge edilir ve docs/analysis/04-security.md public repo'ya düşer. Bir saldırgan dokümanı bulur, :180'deki curl'ü kopyalar, 100 KRW'lık bir SignData basar, kendi ucuz ödemesini tamamlar, dönüş POST'unda Moid'i 1.000.000 KRW'lık bir siparişinkiyle değiştirir. Sipariş 100 KRW karşılığında 'processing' olur, stok düşer, dijital ürün teslim edilir. Tacir A hiçbir uyarı almamıştır çünkü ne advisory ne sürüm artışı ne de Upgrade Notice'te güvenlik uyarısı vardır — ve zaten kurduğu sürüm de "2.0.0"dır.

**Etki**

2.0.0'ın eski halini çalıştıran her tacir için, para kaybettiren dört kritik açığın (order-swap, keyfi tutar imzalama, imza öncesi state yazma, replay) adım adım tarifi herkese açık. Aynı sürüm numarası nedeniyle bir tacir kendi kurulumunun savunmasız olup olmadığını sürüm bilgisinden ANLAYAMAZ; WordPress admin "2.0.0" gösterir, güvenli build de "2.0.0" der. Ne CVE, ne GitHub Security Advisory, ne readme.txt "Upgrade Notice"'inde güvenlik ifadesi var (readme.txt:101-103 sadece "Review all settings after upgrading" diyor). Ekip kendi yazdığı sorumlu açıklama politikasını, o politikanın eklendiği PR'da çiğniyor.

**Öneri**

1) Sürümü derhal 2.1.0'a (veya 3.0.0) çıkar: nicepay-payment-gateway.php:7,22, readme.txt:6, package.json, CHANGELOG.md yeni başlık; `check-version.js` zaten hepsini eşleyecektir. 2) main'deki `## [2.0.0] - 2026-04-03` girdisini GERİ getir ve yeni girdiyi onun üstüne ayrı bir başlık olarak ekle. 3) readme.txt `== Upgrade Notice ==` altına açık bir güvenlik ifadesi koy: "2.0.0 contains order-binding and amount-authority defects that allow an underpaid order to settle. Upgrade immediately." 4) GitHub Security Advisory aç ve CVE talep et. 5) Sömürü adımlarını (04-security.md:116, :180, :288) advisory yayımlanana ve makul bir güncelleme penceresi geçene kadar dokümandan çıkar veya dokümanları private tut — SECURITY.md:18'in kendi sözünü tut.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Yayımlanmamış ama HALKA AÇIK `main` dalında HÂLÂ CANLI olan güvenlik açıklarına karşı çalışan sömürü reçeteleri, aynı public repo'ya `docs/analysis/04-security.md` olarak ekleniyor; düzeltilmiş build de aynı "2.0.0" sürüm numarasını taşıdığı ve hiç git tag'i / GitHub release'i olmadığı için savunmasız ile düzeltilmiş kodu ayırt edecek hiçbir işaret yok.

Doğrulanan çekirdek:
- 04-security.md:116, :180 ve :288-291 tam sömürü adımları + iki çalışır curl içeriyor (satır numaraları doğru; :288 "Exploitation path" başlığı, curl :290).
- Repo PUBLIC (`gh repo view` → isPrivate:false).
- :290'daki curl, `origin/main`'de BUGÜN çalışıyor: class-nicepay-gateway.php:249-272 imza kapısından (satır 275) ÖNCE hem `nicepay_update_transaction(status=failed)` hem `$order->update_status('failed')` yazıyor ve `exit` ediyor. İmzasız, kimliksiz istek yeterli.
- Sürüm ayırt edilemezliği: main 2.0.0 (satır 6, 22), head 2.0.0 (satır 7, 22) + readme.txt:6 + package.json:3; `git tag`, `git ls-remote --tags`, `gh release list` üçü de BOŞ.
- CHANGELOG.md:5'teki `[2.0.0] - 2026-04-03` girdisi `[2.0.0] - 2026-08-20` ile tamamen değiştirilmiş; head'de hiç 2026-04 kaydı yok.
- readme.txt:99-103 Upgrade Notice'te güvenlik ifadesi yok.

Düzeltilen kısımlar:
- "Nisan 2026'da YAYIMLANAN 2.0.0'ı çalıştıran tacirler" iddiası KANITSIZ: main'de `readme.txt` YOK (`git cat-file -e origin/main:readme.txt` → fatal), yani eklenti hiçbir zaman WordPress.org'a sunulabilir durumda olmadı; tag ve release de yok. Tek edinme yolu public repo'yu klonlamak. Kurulu taban doğrulanmamış, muhtemelen sıfır.
- "Ekip kendi SECURITY.md politikasını çiğniyor" YORUM: SECURITY.md:9 ve :18 "## Reporting a vulnerability" başlığı altında ve dilbilgisel olarak RAPORLAYICIYA hitap ediyor ("Please do not..." / "Please allow..."), bakımcıyı bağlayan bir taahhüt değil. Yine de :9 açıkça "pull requests" diyor ve bu bir public PR — ruhen çelişki gerçek, ama "ihlal" fazla güçlü.
- Öneri #1'deki "check-version.js zaten hepsini eşleyecektir" YANLIŞ: .github/scripts/check-version.js:31-43 yalnızca nicepay-payment-gateway.php, CHANGELOG.md, tests/bootstrap/bootstrap.php ve .pot dosyasını okuyor; readme.txt ve package.json'ı HİÇ kontrol etmiyor. Sürüm bumpında bu ikisi sessizce 2.0.0'da kalır.

Asıl aksiyon önceliği değişiyor: mesele "yayımlanmış tacirleri uyarmak" değil, HÂLÂ SAVUNMASIZ olan `main` dalının yanına çalışan sömürü reçetesi koymak. Bu yüzden en acil adım (5) — reçeteleri PR merge'ünden önce dokümandan çıkarmak veya main'i düzeltilmiş koda taşımak — ve (1) sürüm bumpı; (4) CVE/advisory, doğrulanmış kurulu taban olmadığı için isteğe bağlı.
- Gerekçe: I tried hard to break this one and could not break its core. Every load-bearing fact checks out against the files, and one fact the finding only asserted I was able to independently prove: the exploit recipes are aimed at code that is live on the public default branch right now.

WHAT I CONFIRMED (all verified by reading, not inference):

1. The exploit recipes exist at the cited lines. `grep -n "curl -X POST" docs/analysis/04-security.md` returns exactly two hits, 180 and 290. Line 180 is verbatim the 4-step SECURITY-02 recipe quoted in the finding. Line 116 is the 5-step SECURITY-01 Moid-swap recipe. Line 288 is the `**Exploitation path.**` header whose fenced curl sits at 290 (a 2-line off-by-two, cosmetic).

2. The repo is PUBLIC. `gh repo view --json isPrivate,visibility` → `{"isPrivate":false,"visibility":"PUBLIC"}`. Not an assumption.

3. The doc weaponizes a vulnerability that is STILL LIVE on `origin/main` today. This is the part the finding asserted but did not prove, and it is true. I read `git show origin/main:includes/class-nicepay-gateway.php` lines 249-275: the `if ( $auth_result_code !== '0000' )` branch executes `nicepay_update_transaction(... 'status' => 'failed' ...)` and `$order->update_status( 'failed', ... )` and then `exit`s — twenty-six lines BEFORE the `verify_auth_signature()` gate at 275. The curl at 04-security.md:290 (`-d 'Moid=...&AuthResultCode=9999'`) therefore reaches both writes with no signature at all. There is no guard, no nonce, no capability check on that path; I grepped and confirmed the return handler has none. So this is not a recipe against a hypothetical past artifact — it is a working recipe against `git clone && git checkout main` as of this moment.

4. Version indistinguishability is exact. main: `* Version: 2.0.0` (line 6) and `define( 'NICEPAY_VERSION', '2.0.0' )` (line 22). head: same at lines 7 and 22, plus `readme.txt:6 Stable tag: 2.0.0` and `package.json:3 "version": "2.0.0"`. `git tag` → empty. `git ls-remote --tags origin` → empty. `gh release list` → empty. There is genuinely zero discriminator.

5. The CHANGELOG overwrite is real. main CHANGELOG.md:5 is `## [2.0.0] - 2026-04-03` followed by "Complete rewrite of the plugin..." and its own distinct Added list (Shortcode Manager, Display Modes, Default Presets...). head CHANGELOG.md:5 is `## [2.0.0] - 2026-08-20` with entirely different content, and `grep -n "2026-04"` over head's CHANGELOG returns NOTHING. The April release notes are gone, under a header that claims "All notable changes ... are documented in this file."

6. readme.txt:99-103 Upgrade Notice contains no security wording — confirmed verbatim, only "Review all settings after upgrading."

7. SECURITY.md:9 and :18 quoted verbatim and correct.

WHERE THE FINDING OVERREACHES — three corrections:

(a) THE "PUBLISHED / MERCHANTS RUNNING IT" PREMISE IS UNSUPPORTED. The failure scenario opens "Tacir A, Nisan 2026'da yayımlanan 2.0.0'ı canlı KRW mağazasında çalıştırıyor." I found no evidence 2.0.0 was ever released through any channel. `git ls-tree origin/main --name-only` shows NO `readme.txt` on main — the WordPress.org manifest is NEW in this PR, so the plugin has never been submittable to, let alone listed on, WordPress.org. No tags, no GitHub releases. The only acquisition path is cloning the public repo. So the merchant install base is unverified and plausibly zero. That does not void the risk (main is publicly downloadable and the header carries `Update URI` to GitHub), but the vivid "merchant loses 1,000,000 KRW, has no warning" narrative rests on a population nobody has shown exists.

(b) "SECURITY.md POLİTİKASINI İHLAL EDİYOR" IS AN INTERPRETIVE STRETCH. Both cited sentences live inside `## Reporting a vulnerability` and are grammatically addressed to the reporter: ":9 Please do not disclose..." and ":18 Please allow time for merchants to update before publishing technical details." Neither is a maintainer self-binding commitment. There IS a real inconsistency — :9 literally names "pull requests," and this is a public pull request disclosing technical details — but framing it as the team "çiğniyor" its own policy reads more force into the text than it carries.

(c) THE RECOMMENDATION CONTAINS A FACTUAL ERROR. It says "`check-version.js` zaten hepsini eşleyecektir." It will not. `.github/scripts/check-version.js` lines 31-43 read exactly five files: `nicepay-payment-gateway.php`, `CHANGELOG.md`, `tests/bootstrap/bootstrap.php`, `languages/nicepay-payment-gateway.pot`. It never reads `readme.txt` or `package.json`. A bump that relies on this script to catch stragglers will silently leave `readme.txt:6 Stable tag: 2.0.0` and `package.json:3 "version": "2.0.0"` behind — which is precisely the class of drift this finding is complaining about.

SEVERITY: I would set high, not critical. "Critical" in this repo's own table (04-security.md:11) is reserved for confirmed direct money loss. Here the hazard is real and the mechanism is sound — a working unauthenticated exploit recipe published beside still-vulnerable code with no version discriminator, no advisory, no tag — but there is no evidenced exposed install base, and the strongest of the three recipes (SECURITY-02 at :180) additionally requires the attacker to complete a real card payment through NICEPAY on a shop that runs BOTH the shortcode and WooCommerce checkout. The genuinely trivial one is the :290 curl, and its impact is state corruption, not money movement. High is the honest read.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: The public repo currently hosts working, unauthenticated exploit recipes (docs/analysis/04-security.md:180 and :288-293, already pushed to origin/development) against code that is still `origin/main` HEAD — and the remediated build reuses the identical version string "2.0.0" (nicepay-payment-gateway.php:7,22; readme.txt:6; package.json:3) with no git tag anywhere, so the vulnerable and fixed builds are indistinguishable from version metadata alone. CHANGELOG.md:5 additionally replaces main's `## [2.0.0] - 2026-04-03` entry rather than adding a new heading, destroying the record of what the April build contained under a file that claims to document all notable changes, and readme.txt:99-103's Upgrade Notice carries no security language. The exploits are verified reproducible against origin/main (nopriv signing oracle at nicepay-payment-gateway.php:82/322/336; pre-signature order/DB writes at class-nicepay-gateway.php:249-272 ahead of the gate at :275). However, the claim's `critical` rating and its "yayımlanan 2.0.0'ı çalıştıran her tacir" impact are unsupported: there are no git tags, no GitHub releases, no readme.txt in main (so the plugin has never been listed on WordPress.org — readme.txt is new in this PR), and the repo has 0 stars and 0 forks. The realistic vulnerable installed base is plausibly zero, making this a must-fix-before-first-release engineering and disclosure-hygiene defect rather than an active exploitation incident. Separately, framing this as a SECURITY.md policy violation overstates the text: SECURITY.md:9 and :18 sit under "## Reporting a vulnerability" and bind third-party reporters submitting to the maintainer, not the maintainer's own publication of an audit — the spirit of coordinated disclosure is breached because main HEAD is still vulnerable, but the letter of the policy is not.
- Gerekçe: FACTUAL BASE: every checkable assertion holds. I verified all of them.

(a) The exploit recipes exist verbatim at the cited lines. `grep -n "Exploitation path" docs/analysis/04-security.md` → 116, 180, 288, 814. Line 180 is the 4-step SECURITY-02 chain with the admin-ajax curl; lines 290-291 are the second working curl.

(b) The exploits are genuinely reproducible against `origin/main`, not straw men. I read main's source directly:
 - `origin/main:nicepay-payment-gateway.php:82` registers `wp_ajax_nopriv_nicepay_init_payment`; `:322/:328/:336` take `$_POST['amount']` verbatim, gate only on `(float) $amount <= 0`, and return `create_auth_sign_data( $edi_date, $amount )` to the anonymous caller. The signing oracle is real.
 - `origin/main:includes/class-nicepay-gateway.php:235` gates only on `! $transaction || ! $transaction->wc_order_id` — a victim's WC Moid passes. `:249` branches on `$auth_result_code !== '0000'` and performs `nicepay_update_transaction()` + `$order->update_status('failed', ...)` **26 lines before** the signature gate at `:275`. The line-288 curl needs no signature, no nonce, no account. Confirmed unauthenticated.
 - Between `:275` and approval there is no `Amt`/`Moid`/`MID` assertion, so the order-swap in the line-180 recipe works.

(c) Version collision confirmed: dev `nicepay-payment-gateway.php:7` and `:22` = 2.0.0, `readme.txt:6` = 2.0.0, `package.json:3` = 2.0.0; `git show origin/main:nicepay-payment-gateway.php` = `Version: 2.0.0` / `NICEPAY_VERSION '2.0.0'`. `git tag` and `git ls-remote --tags origin` are both EMPTY.

(d) CHANGELOG overwrite confirmed: main line 5 `## [2.0.0] - 2026-04-03`, dev line 5 `## [2.0.0] - 2026-08-20`. Dev's only other heading is `## [1.x]` at line 56 — the entire April changelog body (Shortcode Manager, VBank expiry, refund support, etc., main lines 5-62) is gone, under a file that says at line 3 "All notable changes ... are documented in this file."

(e) `readme.txt:99-103` Upgrade Notice for 2.0.0 says only "Review all settings after upgrading" — zero security language. Confirmed.

CORRECTION 1 — the claim UNDERSTATES the timing, and I should say so: the failure scenario is written as "PR #3 merge edilir ve docs/analysis/04-security.md public repo'ya düşer." It is ALREADY public. `gh repo view` → `"isPrivate":false`, and `git ls-tree -r origin/development --name-only | grep docs/analysis` lists all 15 files including `04-security.md`. Local `development` == `origin/development` (both `6b7fedf`). So right now the public repo simultaneously hosts a working exploit recipe on one branch and the fully vulnerable code as `main` HEAD. The window is live, not conditional on merge.

CORRECTION 2 — severity is OVERSTATED at `critical`, and this is the load-bearing defect in the claim. `critical` rests entirely on the premise "Tacir A, Nisan 2026'da **yayımlanan** 2.0.0'ı canlı KRW mağazasında çalıştırıyor." I found no evidence any version was ever published to any distribution channel:
 - `gh release list` → empty (no GitHub releases, no ZIP artifact).
 - `git ls-remote --tags origin` → empty (no immutable tag to install from).
 - `git ls-tree origin/main --name-only | grep readme` → NOTHING. `readme.txt` is a **new file introduced by this PR**. readme.txt is the WordPress.org manifest, so the plugin has never been listed on WordPress.org; `Stable tag: 2.0.0` is a forward-looking claim, not a record of a shipped release.
 - `gh repo view` → `stargazerCount: 0`, `forkCount: 0`, repo created 2026-04-02, empty description.
The realistic installed base of the vulnerable build is plausibly zero, and the only acquisition path is `git clone` of an undescribed, unstarred, unreleased repo. "Her tacir için ... herkese açık" has no supporting population. There is also no `wc-api` victim to target without a live merchant. This is a serious pre-first-release engineering and disclosure-hygiene defect that MUST be fixed before the first tag, not an active exploitation incident. `high`.

CORRECTION 3 — the "deponun kendi SECURITY.md politikasını ihlal ediyor" framing is a stretch on the letter. Both quoted lines sit under `## Reporting a vulnerability` (SECURITY.md:7) and are addressed to third-party reporters: line 11 says "Use GitHub private vulnerability reporting to send: ...", then line 18 "Please allow time for merchants to update before publishing technical details." That is a rule for someone reporting TO the maintainer, not a self-binding maintainer-publication clause. The maintainer publishing an audit of their own now-fixed code does not breach the text. The coordinated-disclosure SPIRIT is breached — because main HEAD is still vulnerable while the recipe is public — but "çiğniyor" as a policy violation is not what SECURITY.md:9/:18 say.

MINOR: the claim lists "dört kritik açık." The doc's own risk table (04-security.md:11-15) rates only SECURITY-01 Critical; -02/-03/-04/-05 are High, and line 286 self-states SECURITY-04 is "Not critical because it does not itself move money."

The recommendations are all actionable and I confirmed the tooling exists: `.github/scripts/check-version.js`, invoked from `release.yml:39`, `deploy-wordpress-org.yml:91`, and `build-release.sh:23` — so a version bump does need all four files updated together, as the claim says.

---

### META-004 — Planın kendi P0 kapısı sağlanmamış (10 paketten 7'si KISMİ) ve doküman 12 "Horizon 0" listesi Blocks beyanını şart koşuyor — ama PR "release readiness" olarak sunuluyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | claim-vs-gate-contradiction |
| **Konum** | [docs/analysis/13-remediation-action-plan.md:446](../../../docs/analysis/13-remediation-action-plan.md#L446) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
13-remediation-action-plan.md:444-449 "### P0 security candidate" kapısı: ":446 - R01–R08, R10–R11 complete.", ":448 - Zero unresolved critical/high money-state findings." 14-remediation-progress.md'nin kendi paket tablosuna göre bu kapı sağlanmıyor: R01 `KISMİ` (:49), R02 `KISMİ` (:50), R04 `KISMİ` (:52), R06 `KISMİ` (:54), R07 `KISMİ` (:55), R10 `KISMİ` (:58), R11 `KISMİ` (:59) — yani gereken 10 paketin 7'si tamamlanmamış. :42 ayrıca kritik bulgularda "4 kısmi, 1 dış bağımlı" olduğunu kabul ediyor, bu da 13:448'i doğrudan ihlal ediyor. Bağımsız olarak 12-path-to-first-class.md:841 "### Horizon 0 — Must fix before any production use / Do not take live money until all of these are done" başlığı altında :852 satırı EXCELLENCE-10 için "declare `cart_checkout_blocks` + `custom_order_tables`" şartını koyuyor; ekip bilinçli olarak `cart_checkout_blocks` beyan ETMEDİ (nicepay-payment-gateway.php:43-44'te yalnız `custom_order_tables`). Yani doküman 12'nin kendi kriterine göre eklenti hâlâ canlı para almamalı, ve doküman 12'de bu kararın güncellendiğine dair hiçbir not yok.
```

**Başarısızlık senaryosu**

Yeni bir bakımcı devralır, docs/analysis/12-path-to-first-class.md:841 "Do not take live money until all of these are done" listesini yapılacaklar listesi olarak alır, :852'ye bakar, `FeaturesUtil::declare_compatibility('cart_checkout_blocks', ..., true)` satırını ekler ve yayınlar. Böylece hiç tarayıcı sertifikasyonu geçmemiş Blocks checkout'u WooCommerce'e "tam uyumlu" diye bildirmiş olur — 14-remediation-progress.md:92-93'ün tam olarak engellemeye çalıştığı şey.

**Etki**

Repo, birbirini yalanlayan üç kapı taşıyor: doküman 13 "P0 tamamlanmadı", doküman 12 "Horizon 0 tamamlanmadı", PR başlığı ve readme.txt ise yayına hazır bir sürüm sunuyor. Bir karar vericinin hangi kapıya bakacağı belirsiz. Daha kötüsü, doküman 12 hâlâ "cart_checkout_blocks beyan et" diyor ve bunu üretim önkoşulu ilan ediyor; ekip tam tersine karar verdi ama karar hiçbir dokümanda geri yazılmadı. Bu, gelecekte birinin doküman 12'yi izleyip Blocks uyumluluğunu doğrulanmamış şekilde beyan etmesine açık kapı bırakıyor.

**Öneri**

1) 14-remediation-progress.md'ye açık bir "Yayın kapısı durumu" bölümü ekle ve 13:444-449 ile 12:841 kriterlerini tek tek işaretle (geçti/geçmedi + gerekçe). 2) 12-path-to-first-class.md:852'yi düzelt: `cart_checkout_blocks` beyanını Horizon 0'dan çıkar ve "tarayıcı matrisi geçene kadar KASTEN beyan edilmiyor" notunu satırın içine yaz; 12:441'deki aynı ifadeyi de güncelle. 3) PR açıklamasındaki "release readiness" ifadesini "P0 güvenlik adayı — R01/R02/R04/R06/R07/R10/R11 dış kanıt bekliyor" olarak daralt ki başlık kendi kanıtıyla tutarlı olsun.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Bu PR'da yeni eklenen analiz dokümanları arasında gerçek bir tutarsızlık var: 13-remediation-action-plan.md:446/448'deki "P0 security candidate" kapısı R01–R08 + R10–R11'in tamamlanmasını ve sıfır çözülmemiş kritik/yüksek para-durum bulgusunu şart koşuyor, oysa 14-remediation-progress.md:49-59 bu 10 paketten 7'sini `KISMİ`, :42 kritik bulguların 4'ünü kısmi + 1'ini dış bağımlı olarak işaretliyor. Ayrıca 12-path-to-first-class.md:841-843 ("Do not take live money until all of these are done") altındaki :852 satırı ve :441 hâlâ `cart_checkout_blocks` beyanını üretim önkoşulu sayıyor, ekip ise nicepay-payment-gateway.php:36-44'te bunu bilinçli olarak beyan etmiyor ve doküman 12'de bu kararın değiştiğine dair hiçbir not yok. Commit/PR başlığı "release readiness" bu kapılarla hizalı değil.

ANCAK iddianın üç detayı yanlıştır: (1) readme.txt çelişen bir kapı DEĞİLDİR — readme.txt:16, :23 ve :69 gateway'in varsayılan kapalı olduğunu ve Blocks uyumluluğunun kasten beyan edilmediğini açıkça yazar, yani doküman 14 ile tutarlıdır; (2) 14-remediation-progress.md:85-100 zaten "Canlı yayın öncesi gerçek engeller" adlı açık bir yayın kapısı bölümü içerir ve :99-100 Blocks beyanının güvensiz olduğunu söyler, dolayısıyla "hangi kapıya bakılacağı tamamen belirsiz" abartılıdır; eksik olan yalnızca 13:444-449 ve 12:841 kriterlerine madde madde atıf yapılmamasıdır; (3) başarısızlık senaryosu teknik olarak engellidir — tests/integration/woocommerce-smoke.php:53-56'daki `! in_array( 'cart_checkout_blocks', ... )` assert'i, doküman 12:852'yi izleyen bir bakımcının beyanı eklemesi hâlinde gerçek WooCommerce entegrasyon testinde CI'ı kırar; "doğrulanmamış Blocks beyanıyla sessizce yayınlama" yolu kapalıdır. Bu nedenle bulgu gerçek bir dokümantasyon tutarlılığı borcudur ama para/üretim riski taşımaz: severity `medium`.
- Gerekçe: Her satır referansını açıp okudum. İddianın ÇEKİRDEĞİ tamamen doğrulandı: (1) 13-remediation-action-plan.md:444-449'daki "P0 security candidate" kapısı gerçekten "R01–R08, R10–R11 complete" ve "Zero unresolved critical/high money-state findings" şartını koyuyor; (2) 14-remediation-progress.md'nin kendi tablosunda o 10 paketten 7'si (R01:49, R02:50, R04:52, R06:54, R07:55, R10:58, R11:59) `KISMİ` ve :42 "4 kısmi, 1 dış bağımlı" diyor — kapı sağlanmıyor; (3) 12-path-to-first-class.md:841 "Do not take live money until all of these are done" başlığı altında :852 hâlâ `cart_checkout_blocks` beyanını şart koşuyor, :441 aynı ifadeyi tekrarlıyor; (4) nicepay-payment-gateway.php:41-50'de yalnızca `custom_order_tables` beyan ediliyor (43-44), Blocks kasten beyan edilmiyor; (5) doküman 12'de bu kararın tersine çevrildiğine dair hiçbir not yok (repo genelinde `cart_checkout_blocks` grep'i doğruladı, doc 12'de yalnız :441 ve :852 geçiyor, ikisi de "beyan et" diyor). Commit başlığı da gerçekten "feat: harden payment lifecycle and release readiness".

Ancak İDDİANIN DETAYLARI ÜÇ NOKTADA YANLIŞ/ABARTILI, bu yüzden severity düşürülmeli:

(a) "readme.txt yayına hazır bir sürüm sunuyor" YANLIŞ. readme.txt:23 ve :69 tam tersini açıkça yazıyor: "Cart and Checkout Blocks compatibility is not declared until the real browser certification matrix is complete." ve readme.txt:16 gateway'in fresh install'da kapalı olduğunu söylüyor. readme kapıyı ihlal etmiyor, tam tersine doküman 14 ile hizalı. "Birbirini yalanlayan üç kapı" tablosunun üçüncü ayağı çürütüldü — gerçekte iki eski analiz dokümanı vs. güncel durum dokümanı var.

(b) "Bir karar vericinin hangi kapıya bakacağı belirsiz" iddiası kısmen çürütülüyor: 14-remediation-progress.md:85-100 zaten "Canlı yayın öncesi gerçek engeller" başlıklı, numaralandırılmış ve :99-100'de "Bu engeller çözülmeden canlı gateway'i varsayılan olarak açmak ... veya Cart/Checkout Blocks uyumluluğu ilan etmek güvenli değildir" diyen açık bir yayın kapısı bölümü içeriyor. İddianın önerdiği "Yayın kapısı durumu bölümü ekle" işinin bir bölümü zaten var; eksik olan, 13:444-449 ve 12:841 kriterlerine madde madde referans verilmemesi.

(c) BAŞARISIZLIK SENARYOSU TEKNİK BİR KORUMAYLA ENGELLENİYOR. `tests/integration/woocommerce-smoke.php:53-56` şu assert'i içeriyor: `! in_array( 'cart_checkout_blocks', $compatible_features['compatible'], true )` — mesaj: "Checkout Blocks compatibility was declared without the browser matrix." Yani doküman 12:852'yi izleyip beyanı ekleyen bir bakımcı sessizce yayınlayamaz; gerçek WooCommerce entegrasyon testi CI'da KIRILIR. İddia bu koruyucuyu hiç aramamış/anmamış. Bu, "açık kapı bırakıyor" etkisini ciddi biçimde zayıflatır — kalan risk sadece kafa karışıklığı ve CI'ı da düzeltmeye çalışan bir bakımcının fazladan iş yapması.

Ek bağlam: `git diff --numstat origin/main...development -- docs/analysis/` gösteriyor ki 00–14 arası TÜM analiz dokümanları bu PR'da yeni eklendi (X ekleme / 0 silme). Doküman 12 kendi girişinde (:12-17) "Every finding below carries the verdict `PLAUSIBLE`, not `CONFIRMED`. No PHP runtime, no vendor/ ... nothing was executed" diyerek kendini yürütülmemiş bir ön-inceleme anlık görüntüsü olarak ilan ediyor. Bu, 12'nin canlı bir yayın kapısı değil, tarihsel bir inceleme kaydı olduğu okumasını destekler — ama "Do not take live money until all of these are done" cümlesi bu çerçeveyi metnin içinde belirtmediği için karışıklık riski yine de gerçektir.

Sonuç: olgusal çekirdek doğru, dokümantasyon tutarsızlığı gerçek ve düzeltilmeli; ancak somut zarar yolu CI ile kapalı ve readme yanlış beyanda bulunmuyor. `high` değil `medium`.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `low`
- Düzeltilmiş iddia: docs/analysis/12-path-to-first-class.md:441 ve :852, remediation sırasında verilen "Cart/Checkout Blocks uyumluluğu tarayıcı matrisi geçene kadar KASTEN beyan edilmeyecek" kararından sonra güncellenmemiştir; :852 hâlâ `cart_checkout_blocks` beyanını "Horizon 0 — canlı para almadan önce şart" listesinde tutmaktadır (12:841-843). Ayrıca 14-remediation-progress.md, 13-remediation-action-plan.md:444-448'deki P0 kapısını madde madde işaretleyen bir "kapı durumu" bölümü içermemektedir; okuyucu 13:446'nın istediği 10 paketten 7'sinin `KISMİ` olduğunu (14:49,50,52,54,55,58,59) ancak tabloyu elle karşılaştırarak çıkarabilir. Bu bir doküman senkronizasyon eksikliğidir, çelişkili yayın kapısı değil: (a) 14:19 `KISMİ`yi zaten "dış kanıt bekliyor" olarak tanımlar ve 14:88-93 engelleri listeler, dolayısıyla doküman 14 hiçbir yerde P0'ın geçildiğini iddia etmez; (b) readme.txt:23 ve :25 Blocks beyanının yapılmadığını ve üretim öncesi sandbox sertifikasyonunun şart olduğunu açıkça yazar, yani "readme yayına hazır sunuyor" iddiası yanlıştır; (c) doküman 12'nin tamamı 12:12-17'de `PLAUSIBLE / nothing was executed` uyarısıyla tarihli bir girdi analizi olarak işaretlidir. Bakımcının :852'yi izleyip beyanı ekleme senaryosu ise sessizce yayına ulaşamaz: tests/integration/woocommerce-smoke.php:54-56 gerçek WooCommerce üzerinde bu beyanı reddeden bir assert koşar ve CI'ı kırar; nicepay-payment-gateway.php:37-39 de kararı kod içinde açıklar. Kalan risk yalnızca kafa karışıklığı ve boşa harcanan efordur.
- Gerekçe: Ham olgular doğru, çıkarım ve etki abartılı.

DOĞRULANAN KISIM (satır satır okudum, hepsi tutuyor):
- 13-remediation-action-plan.md:444/446/448 "P0 security candidate" kapısı tam olarak iddia edildiği gibi yazılı.
- 14-remediation-progress.md paket tablosunda R01(:49), R02(:50), R04(:52), R06(:54), R07(:55), R10(:58), R11(:59) `KISMİ`; yalnız R03(:51), R05(:53), R08(:56) `DÜZELTİLDİ`. Yani 13:446'nın istediği 10 paketin 7'si tamamlanmamış — sayı doğru.
- 14:42 gerçekten "9 düzeltildi, 4 kısmi, 1 dış bağımlı" diyor.
- 12-path-to-first-class.md:841 "Do not take live money until all of these are done" başlığı altında :852 hâlâ `cart_checkout_blocks` beyanını şart koşuyor; :441 aynı ifadeyi tekrar ediyor; nicepay-payment-gateway.php:41-50 yalnız `custom_order_tables` beyan ediyor. Doküman 12 içinde bu kararın değiştiğine dair not YOK — bu tespit de doğru.

ÇÜRÜTÜLEN/DÜZELTİLEN KISIMLAR (lens: sonuç ve istismar edilebilirlik):

1) Başarısızlık senaryosu pratikte BLOKE. İddia "yeni bakımcı :852'yi izler, declare_compatibility ekler ve yayınlar" diyor. Ama tests/integration/woocommerce-smoke.php:54-56 gerçek WooCommerce 11.0.1 üzerinde `! in_array( 'cart_checkout_blocks', $compatible_features['compatible'], true )` assert'ini koşuyor, mesajı da "Checkout Blocks compatibility was declared without the browser matrix." Yani beyan eklenirse CI KIRILIR — sessiz yayın yolu yok. Üstelik beyanın hemen üstünde nicepay-payment-gateway.php:37-39 yorumu niyeti açıkça yazıyor. Senaryonun gerçekleşmesi için bakımcının hem doc 12'yi izlemesi hem kırmızı CI assert'ini bilinçli silmesi gerekir — bu artık "kaçırılmış doküman güncellemesi" değil, kasıtlı bir eylem.

2) "PR başlığı ve readme.txt yayına hazır sürüm sunuyor" iddiası YANLIŞ. readme.txt:23 "Cart and Checkout Blocks compatibility is not declared until the real browser certification matrix is complete." ve readme.txt:25 "Before accepting production payments, the merchant must complete NICEPAY provisioning and sandbox certification" diyor. readme.txt, doküman 14 ve kodla tamamen tutarlı; üçüncü çelişkili kapı diye sunulan şey mevcut değil.

3) "Doküman 14 kendi kapısını ihlal ediyor" çerçevesi de zayıf: 14:19 `KISMİ`yi zaten "güvenli kod tarafı tamam, kabul ölçütünün bir bölümü dış kanıt bekliyor" olarak tanımlıyor ve 14:88-96 canlı yayın öncesi engelleri madde madde sayıyor. Doküman 14 hiçbir yerde P0 kapısının geçildiğini İDDİA ETMİYOR — dolayısıyla "kapı sağlanmadı" bir tutarsızlık değil, dokümanın kendi beyanı.

4) Doküman 12 bir "kapı" değil, tarihli bir GİRDİ analizi: 12:12-17 "Every finding below carries the verdict `PLAUSIBLE`, not `CONFIRMED` ... nothing was executed" diyor ve 01-findings-index.md:132 EXCELLENCE-10'u "**PLAUSIBLE** — needs confirmation" olarak listeliyor. Yani "üç birbirini yalanlayan kapı" tablosu yanlış; gerçek artık, remediation sonrası revize edilmemiş eski bir yol haritası satırından ibaret.

SONUÇ: geriye kalan gerçek sorun, doküman 12:441/852'nin remediation kararıyla senkron olmaması ve 14'te tek bir "kapı durumu" tablosunun bulunmaması — yani doküman hijyeni. Kod, test ve readme üç ayrı yerde doğru davranışı kilitlediği için `high` fazla; `low` uygun. Önerinin 2. maddesi (12:441/852'ye "tarayıcı matrisi geçene kadar kasten beyan edilmiyor" notu) hâlâ geçerli ve ucuz bir düzeltme; 3. madde (readme/PR iddiası) ise dayanaksız.

---

### META-006 — CONFIGURATION.md'nin geri dönüşsüz silme öncesi önerdiği "CSV'yi dışa aktar, yedeği doğrula" adımı, 10.000 satırlık sessiz dışa aktarma sınırını hiç söylemiyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | doc-code-mismatch |
| **Konum** | [docs/CONFIGURATION.md:71](../../../docs/CONFIGURATION.md#L71) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```markdown
docs/CONFIGURATION.md:66-71, kalıcı finansal kayıt silmeyi etkinleştirme prosedürü: "1. Choose **Permanently delete eligible NicePay financial records after**. 2. Enter a whole number from 1 to 36,500 days. 3. Read and select the permanent-deletion acknowledgement. 4. Export the relevant transaction CSV, verify a recoverable backup, and save." Ancak dışa aktarma sert şekilde kapaklı: admin/class-nicepay-transactions.php:13 `const CSV_MAX_ROWS = 10000;` ve :135 `$max_rows = max( 1, min( self::CSV_MAX_ROWS, absint( $max_rows ) ) );`. Sınır fazlası satırlar sessizce atlanır; kullanıcıya kesme uyarısı verilmez, yalnızca buton yanında bir önizleme metni vardır (:697 "Exports up to %s matching rows.") ve bir HTTP başlığı (:263 `X-NicePay-Export-Row-Limit`). CONFIGURATION.md içinde "10,000" yalnızca ilgisiz bağlamlarda geçiyor (:221 shortcode preset tutarı, :448 tutar formatı örneği); dışa aktarma sınırı hiçbir shipped dokümanda geçmiyor. 14-remediation-progress.md:60 ise bu CSV'yi "formül enjeksiyonuna dayanıklı sınırlı CSV" diye olumlu bir başarı olarak sayıyor — "sınırlı" olmasının veri kaybı riski hiçbir yerde işlenmiyor.
```

**Başarısızlık senaryosu**

Orta ölçekli bir Kore mağazası 3 yılda 45.000 NicePay işlemi biriktirmiş. Yönetici CONFIGURATION.md:66-71'i izler: 730 günlük saklama süresi girer, onay kutusunu işaretler, "Export filtered CSV"e basar (10.000 satır iner), dosyayı "yedeğim var" diye kaydeder ve ayarı kaydeder. Cron 500 kayıt/gün silmeye başlar. 70 gün sonra 35.000 satır kalıcı olarak gitmiştir ve hiçbiri yedekte değildir. Bir müşteri 2 yıl önceki bir sipariş için iade talep ettiğinde, ledger satırı yok olduğu için eklenti üzerinden iade başlatılamaz.

**Etki**

Dokümante edilmiş güvenlik adımının kendisi güvenli değil. Retention silme geri alınamaz (CONFIGURATION.md:73 "Saving the setting does not restore records already deleted" ve :77 "Once such a local ledger row is deleted, a later refund cannot be initiated through this plugin"). 10.000'den fazla işlem kaydı olan bir tacir dokümandaki adımı harfiyen uygular, eksik bir yedek alır, sonra kalıcı silmeyi açar. Kaybolan satırlar için gelecekte iade başlatılamaz — doğrudan finansal ve yasal sonuç.

**Öneri**

CONFIGURATION.md:71'i düzelt ve sınırı açıkça yaz:

```markdown
4. **Yedek al.** Yönetici ekranındaki "Export filtered CSV" tek seferde en fazla
   **10.000 satır** verir (`CSV_MAX_ROWS`). Daha fazla kaydınız varsa tarih/durum
   filtrelerini daraltarak parça parça dışa aktarın ve toplam satır sayısının
   listedeki kayıt sayısıyla eşleştiğini doğrulayın, ya da doğrudan
   `{prefix}nicepay_transactions` ve `{prefix}nicepay_refund_attempts`
   tablolarının veritabanı yedeğini alın. Kısmi bir CSV, geri dönüşsüz silme
   için yeterli yedek DEĞİLDİR.
```

Ek olarak kodda savunma ekle: `write_csv_export()` eşleşen satır sayısı `CSV_MAX_ROWS`'u aştığında son satıra bir `# TRUNCATED: N of M rows exported` işareti bassın ve retention onay kutusunun yanında "Ledger'ınızda %d kayıt var; CSV dışa aktarma en fazla 10.000 satır verir" uyarısı gösterilsin.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: CONFIGURATION.md:71 ve admin/class-nicepay-admin.php:551, geri dönüşsüz saklama-silme öncesi yedek prosedürü olarak "filtrelenmiş CSV'yi dışa aktar" adımını öneriyor; oysa export `CSV_MAX_ROWS = 10000` ile sert şekilde kapaklı (admin/class-nicepay-transactions.php:13, :135) ve kesilme dosyanın içinde hiçbir şekilde işaretlenmiyor — `handle_csv_export()` (:270) `write_csv_export()`'un döndürdüğü satır sayısını yok sayıyor; tek sinyal hiçbir tarayıcının göstermediği `X-NicePay-Export-Row-Limit` başlığı (:263). Ne doküman ne de ürün içi uyarı bu sınırdan söz ediyor. Bununla birlikte iddianın "kullanıcı hiçbir şey göremez" tonu abartılı: export ekranında "Exports up to 10,000 matching rows." (:696-697) metni ile "Total: %d transactions" (:706-712) yan yana duruyor; doküman CSV'yi tek yedek ilan etmiyor, ayrıca "verify a recoverable backup" diyor; retention modu varsayılan değil, onay kutusuyla fail-closed korunuyor (includes/class-nicepay-retention.php:95) ve silme 500 kayıt/gün hızında ilerlerken ekranda eligible/next-run/last-run sayaçları gösteriliyor (admin/class-nicepay-admin.php:568, :577, :590). Dolayısıyla bu, kayıp için beş bağımsız koşulun üst üste gelmesini gerektiren bir doküman+UX eksikliğidir; düzeltme hem CONFIGURATION.md:71'i hem admin/class-nicepay-admin.php:551'i kapsamalı ve koda `# TRUNCATED: N of M` işareti ile `$total > CSV_MAX_ROWS` durumunda inline uyarı eklenmelidir.
- Gerekçe: Her ham olgu doğru ve satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Ancak "istismar edilebilirlik / sonuç" merceğinden bakınca severity `high` abartılmış; ayrıca iddianın konum listesi eksik (aynı yanıltıcı yönerge kodun kendi UI'ında da var, bu yönüyle iddia hafife alınmış).

DOĞRULANANLAR:
1. docs/CONFIGURATION.md:71 gerçekten "Export the relevant transaction CSV, verify a recoverable backup, and save." diyor — 10.000 sınırına dair tek kelime yok.
2. `const CSV_MAX_ROWS = 10000;` (:13) ve `$max_rows = max(1, min(self::CSV_MAX_ROWS, absint($max_rows)));` (:135) — sert, aşılamaz kapak. `write_csv_export()` `$exported` döndürüyor ama `handle_csv_export()` (:270) dönüş değerini tamamen yok sayıyor; dosyanın içine hiçbir kesme işareti yazılmıyor.
3. Kesilme sinyali yalnızca `X-NicePay-Export-Row-Limit` başlığı (:263) — hiçbir tarayıcı bunu göstermez — ve buton yanındaki statik metin (:696).
4. `grep` ile doğrulandı: shipped dokümanların hiçbirinde export satır sınırı geçmiyor; CONFIGURATION.md'deki "10,000" örnekleri (:221, :448) ilgisiz.
5. Geri dönüşsüzlük iddiası doğru: :73 "Saving the setting does not restore records already deleted", :77 "a later refund cannot be initiated through this plugin".
6. Senaryonun aritmetiği tutarlı (500 kayıt/gün × 70 gün = 35.000).

İDDİANIN KAÇIRDIĞI (severity'yi ARTIRAN yön — konum eksik):
Aynı yanıltıcı yönerge dokümanla sınırlı değil; ürünün kendi uyarı kutusunda da var: admin/class-nicepay-admin.php:551 "Deleting a paid or partially refunded ledger record prevents future refunds through this plugin. **Export the filtered CSV and verify a recoverable backup** before enabling deletion." Yani bu salt bir doc-code mismatch değil, kod↔kod tutarsızlığı: aynı sınıfın UI'ı CSV'yi yedek prosedürü olarak öneriyor, başka bir sınıf onu sessizce 10.000'de kesiyor. İddia bu konumu hiç anmıyor.

SEVERITY'Yİ DÜŞÜREN GERÇEK ENGELLER (bu yüzden high değil medium):
a) Doküman CSV'yi tek yedek olarak sunmuyor — iki ayrı emir veriyor: "Export the ... CSV" **ve** "verify a recoverable backup". "Recoverable backup" doğal olarak DB/host yedeği anlamına gelir; CSV'yi yeterli yedek ilan eden bir cümle yok. Senaryonun "dosyayı 'yedeğim var' diye kaydeder" adımı, dokümanın harfiyen uygulanması değil, ikinci maddenin atlanmasıdır.
b) Kesilme ekranda tamamen sessiz değil: export butonunun yanındaki "Exports up to 10,000 matching rows." (:696) ile hemen altındaki "Total: %d transactions" (:706-712) aynı ekranda, yan yana. 45.000 satırlık mağazada yönetici "Total: 45,000" ile "Exports up to 10,000" ifadelerini aynı anda görür. Uyarı korelasyonu yok ama veri gizli de değil.
c) Yol default değil ve üç kapıdan geçiyor: mod `indefinite` varsayılan, gün sayısı 1–36.500 aralığında geçerli olmalı, ve `acknowledged !== 'yes'` ise fail-closed reddediliyor (includes/class-nicepay-retention.php:95-96). Ayrıca UI'da tam ekran bir `notice notice-warning` bloğu var (admin/class-nicepay-admin.php:545-552).
d) Silme yavaş ve gözlemlenebilir: 500/gün, üstelik ekranda "Records currently eligible: %s" (:568), "Next scheduled cleanup" (:577) ve "Last cleanup (UTC): ...; deleted: %2$d" (:590-596) gösteriliyor. 35.000 satırın gitmesi 70 gün sürer ve her gün sayaç yönetici ekranında görünür — tespit/durdurma penceresi geniştir.
e) Filtreler (date_from/date_to/status/method) mevcut, yani parçalı export teknik olarak mümkün; eksik olan yalnızca bunu söyleyen doküman.

Sonuç: gerçek, üretilebilir ve finansal sonucu olan bir doküman eksikliği — ama tetiklenmesi için yöneticinin non-default bir ayarı bilinçli onay kutusuyla açması, ekrandaki iki sayıyı yan yana görüp göz ardı etmesi, dokümanın ikinci emrini (recoverable backup) atlaması, site/host DB yedeği olmaması ve 70 gün boyunca "deleted: N" sayacını izlememesi gerekiyor. Bu beş bağımsız koşulun birleşimi `high` değil `medium`. Önerilen düzeltme (doküman + CSV içine `# TRUNCATED` satırı + toplam sayı > CSV_MAX_ROWS ise inline uyarı) doğru ve uygulanabilir; buna admin/class-nicepay-admin.php:551 metninin de düzeltilmesi eklenmeli.

---

### META-003 — 13-remediation-action-plan.md'nin zorunlu kıldığı bulgu-başına "closure ledger" hiç üretilmemiş; 484 bulgunun tek tek durumu hiçbir yerde yok

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | missing-evidence-artifact |
| **Konum** | [docs/analysis/13-remediation-action-plan.md:414](../../../docs/analysis/13-remediation-action-plan.md#L414) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
13-remediation-action-plan.md:414 açıkça şart koşuyor: "During implementation, the canonical index receives a generated closure ledger with `ID`, `owner package`, `status`, `evidence commit`, `test`, and `external dependency`. A release candidate fails if any row is blank, duplicated between owners, or closed without evidence." 13:460 (Release candidate kapısı) tekrar ediyor: "Every row in the closure ledger has evidence." Böyle bir ledger repoda yok: `docs/analysis/` altında yalnızca 00-14 var, 01-findings-index.md'de status/evidence sütunu yok (sütunlar: ID, Dimension, Finding, Primary location, Effort, Verdict — 01:11-18), ve 14-remediation-progress.md kasten paket seviyesinde kalıyor: 14:9-12 "Analizdeki 484 numaralı kayıt/489 doğrulanmış bulgu, aynı kök nedenleri farklı açılardan tekrar ettiği için paket bazında ele alınmıştır." 13:19 ise "`WONTFIX` is not an acceptable closure state for this program" diyor — ama 484 satırın hangisinin hangi durumda olduğu hiçbir yerden okunamıyor.
```

**Başarısızlık senaryosu**

Ekip yayına hazırlanır. Kod sahibi "kritik bulguların 9'u düzeltildi" der. Bir inceleyici bunu doğrulamak için 01-findings-index.md'yi açar — orada statü sütunu yoktur. 14-remediation-progress.md'yi açar — orada yalnızca 14 kritik bulgu tek tek listelenmiştir; kalan 470 bulgu (66 high dahil) hiçbir yerde tek tek görünmez. Örneğin SECURITY-14 (Low) veya PLATFORM-23 (Medium) kapandı mı, hâlâ açık mı, yoksa kasten mi bırakıldı — cevap yok. Yayın kararı, doğrulanamayan bir toplu iddiaya dayanarak verilir.

**Etki**

Planın kendi tanımladığı release-blocking artefakt üretilmedi, ama PR yine de "release readiness" iddiasıyla geliyor. Bir inceleyici "UX-063 kapandı mı?" veya "CODE-37 ne oldu?" sorusuna cevap veremez; 20 paketin prozaik özetinden 484 satıra geri eşleme yapmak elle imkânsız. Bu, ilerlemenin doğrulanamaz olduğu anlamına gelir — dolayısıyla "9 düzeltildi, 4 kısmi" gibi toplam iddialar da bağımsız olarak sağlanamaz. Bakım açısından da kalıcı bir borç: bir sonraki geliştirici 484 bulguyu yeniden taramak zorunda kalır.

**Öneri**

01-findings-index.md'ye üç sütun ekle ve üretimini scriptle: `Status` (FIXED / SUPERSEDED / VERIFIED_NOT_APPLICABLE / EXTERNAL_BLOCKED / OPEN), `Owner package` (R01..R24), `Evidence` (commit SHA veya test adı). Minimum yürütülebilir hali: `docs/analysis/closure-ledger.csv` + `.github/scripts/check-closure-ledger.js` ile (a) 01-findings-index.md'deki her ID'nin ledger'da bulunması, (b) hiçbir satırın boş status/evidence taşımaması, (c) her paketin en az bir bulgu sahiplenmesi doğrulanır. En azından high+critical (80 satır) için bunu manuel olarak doldurmak bile bugünkü durumdan kat kat iyidir.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 13-remediation-action-plan.md:414'ün zorunlu kıldığı bulgu-başına "closure ledger" artefaktı üretilmemiş: repoda ledger dosyası yok, 01-findings-index.md'de status/owner/evidence sütunu yok (01:11-16) ve 14-remediation-progress.md yalnızca 14 kritik bulguyu ve 20 paketi tek tek ele alıyor (dokümanda geçen benzersiz bulgu ID'si: 14; index'te: 485). Bu, 470 bulgu için satır seviyesinde izlenebilirlik borcu yaratır ve plandaki RC kapısı (13:460 "Every row in the closure ledger has evidence") teknik olarak doğrulanamaz durumdadır. Ancak PR "release candidate geçildi" iddiasında bulunmuyor: 14:49-68 paketlerin çoğunu `KISMİ`/`DIŞ BAĞIMLI` işaretliyor ve 14:85-100 canlı yayın öncesi dört somut engeli açıkça sayarak "bu engeller çözülmeden canlı gateway'i varsayılan olarak açmak ... güvenli değildir" diyor. Ayrıca örnek olarak verilen SECURITY-14 Low değil Medium'dur (01:194, bölüm başlığı 01:137).
- Gerekçe: Çekirdek iddia tamamen doğrulandı: satır numaraları dosyaların şu anki haliyle birebir eşleşiyor, alıntılar birebir doğru ve iddiayı çürütecek hiçbir artefakt repoda yok.

Doğruladıklarım:
1) 13-remediation-action-plan.md:414 gerçekten bulgu-başına "generated closure ledger" (ID/owner/status/evidence commit/test/external dependency) şart koşuyor ve "A release candidate fails if any row is blank..." diyor. :391 bölüm başlığı, :460 RC kapısı ve :19 WONTFIX cümlesi de iddia edildiği yerlerde.
2) Ledger fiziksel olarak YOK. `find` ile repo genelinde `*ledger*`/`*closure*` isimli hiçbir dosya yok; "closure ledger" ifadesi yalnız 13'te (391/414/460) ve incelemenin kendi pr-review2 dosyasında geçiyor. `docs/analysis/` altında yalnız 00–14 + pr-review2/ var.
3) 01-findings-index.md'de status/evidence sütunu yok — sütunlar tam olarak ID, Dimension, Finding, Primary location, Effort, Verdict (01:11–16).
4) Kapsam farkı ölçülebilir: 14-remediation-progress.md toplam 100 satır ve içinde geçen benzersiz bulgu ID'si sadece 14; 01-findings-index.md'de 485 benzersiz ID var. Yani 470 bulgunun tek tek durumu gerçekten hiçbir yerde okunamıyor. 14:9-12 bunun bilinçli olduğunu ("paket bazında ele alınmıştır") açıkça söylüyor.
5) İddiadaki "9 düzeltildi, 4 kısmi" sayısı da doğru (14:26-40 kritik tablosu: 9 DÜZELTİLDİ, 4 KISMİ, 1 DIŞ BAĞIMLI).

Düzelttiğim/hafifleten noktalar (bu yüzden tam "confirmed" değil):
a) Başarısızlık senaryosundaki "SECURITY-14 (Low)" örneği yanlış: SECURITY-14 01-findings-index.md:194'te ve bulunduğu bölüm "🟡 Medium — 246 findings" (bölüm başlığı 01:137). Low değil, Medium. PLATFORM-23 (01:282) ise gerçekten Medium — o kısım doğru.
b) Etki abartılı: "Yayın kararı doğrulanamayan bir toplu iddiaya dayanarak verilir" senaryosu, doküman 14'ün kendisinin RC kapısını geçtiğini İDDİA ETMEMESİ ile zayıflıyor. 14:45-68 tablosunda 20 paketin çoğu `KISMİ`, R09 `DIŞ BAĞIMLI`, R20–R24 `PLANLANDI`; 14:85-100 "Canlı yayın öncesi gerçek engeller" dört maddeyi tek tek sayıyor ve son cümlesi "Bu engeller çözülmeden canlı gateway'i varsayılan olarak açmak ... güvenli değildir" diyor. Yani eksik artefakt gerçek bir izlenebilirlik/bakım borcu ama "release readiness yanlış beyanı" değil — plan kendi RC kapısının geçilmediğini zaten kabul ediyor.
c) 13:391-397 ayrıca statüyü açıkça 14'e delege ediyor ("The current evidence-backed implementation status is maintained in 14-remediation-progress.md"), dolayısıyla plan tamamen sessiz değil; eksik olan spesifik olarak bulgu-başına granülerlik.

Bu nedenlerle severity high yerine medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 13-remediation-action-plan.md:414 ve :460 kendi tanımladığı bulgu-başına "closure ledger" (ID / owner package / status / evidence commit / test / external dependency) artefaktı repoda üretilmemiştir; 01-findings-index.md'nin satır tablosunda status/owner/evidence sütunu yoktur (başlık satırı 01:41 — `| ID | Dimension | Finding | Primary location | Effort | Verdict |`) ve 14-remediation-progress.md yalnızca 14 kritik bulguyu tek tek (14:27-40), geri kalanı paket bazında (14:49-68) ele alır. Bu doğrulanabilirlik/bakım borcudur; ancak iddianın "release-blocking artefakt üretilmedi ama PR yine de release readiness iddiasıyla geliyor" çerçevesi abartılıdır: 14-remediation-progress.md bu sapmayı açıkça beyan ediyor (14:9-12), paketlerin çoğunu `KISMİ`/`DIŞ BAĞIMLI` bırakıyor ve 14:85-100'de "Canlı yayın öncesi gerçek engeller" başlığı altında yayının güvenli olmadığını açıkça söylüyor — yani hiçbir yerde "Release candidate kapısı geçildi" iddiası yok. Ayrıca failure_scenario'daki "SECURITY-14 (Low)" yanlıştır; 01:194 bu bulguyu **Medium** olarak listeler.
- Gerekçe: Olgusal çekirdeğin tamamını doğruladım: alıntılanan üç satır (13:391, 13:414, 13:460) birebir iddia edildiği gibi; `find . -iname "*ledger*"` ve `find docs -iname "*.csv"` hiçbir sonuç döndürmüyor, yani ledger artefaktı gerçekten yok; 01-findings-index.md'nin beş ayrı tablo başlığı da (satır 41, 65, 141, 397, 542) status/owner/evidence sütunu içermiyor; 14-remediation-progress.md'de birey bazlı tek tablo 14:27-40'taki 14 kritik bulgudur. SECURITY-14, PLATFORM-23, UX-063 grep'i bu ID'lerin yalnızca 01-findings-index.md'de (statüsüz satır olarak) geçtiğini gösteriyor — yani "kapandı mı?" sorusunun cevabı gerçekten hiçbir yerde okunamıyor. Failure scenario TAM olarak üretilebilir: özel bir rol, istek veya zamanlama gerekmez, iki dosyayı açmak yeterlidir; bu yönüyle "ulaşılamaz kod yolu" itirazı geçersizdir.

Ancak SONUÇ/İSTİSMAR merceğinden severity `high` fazla yüksek: (a) İstismar edilebilir bir yüzey yok; bu tamamen süreç/denetlenebilirlik borcu. (b) 13:414'teki cümle gelecek zamanlı bir plan taahhüdü ("During implementation, the canonical index receives...") ve 13:460 yalnızca "Release candidate" kapısının bir maddesi — hiçbir belge bu kapının geçildiğini iddia etmiyor. Aksine 14:85-100 dört somut engel sayıp "Bu engeller çözülmeden canlı gateway'i varsayılan olarak açmak ... güvenli değildir" diyor, R09/R20-R24 `DIŞ BAĞIMLI`/`PLANLANDI`, paketlerin 14'ü `KISMİ`. (c) 14:9-12 paket-bazlı ele alışı kasıtlı ve gerekçeli olarak beyan ediyor; yani gizlenmiş/yanıltıcı bir kapanış iddiası değil, açık bir kapsam kısıtı. Dolayısıyla gerçek zarar "yanlış yayın kararı" değil, "bir sonraki geliştirici/denetçi için geri-eşleme maliyeti" — bu medium'a oturur. Ayrıca iki küçük hata: failure scenario SECURITY-14'ü Low sanıyor (01:194 gerçekte Medium) ve kanıt "docs/analysis/ altında yalnızca 00-14 var" diyor; gerçekte bir de `docs/analysis/pr-review2/` dizini var (PR diff'inde değil, bu incelemenin kendi çıktısı) — ikisi de çekirdeği değiştirmiyor.

---

### META-005 — 14-remediation-progress.md'nin doğrulama kanıtı PR gerçekliğinin gerisinde: 387/1074 vs 393/1112, 39 vs 42 PHP dosyası, ve dosya hâlâ "commit veya push yapılmadı" diyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | stale-evidence |
| **Konum** | [docs/analysis/14-remediation-progress.md:72](../../../docs/analysis/14-remediation-progress.md#L72) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
14-remediation-progress.md:72 "PHP 7.4, 8.0, 8.1, 8.2 ve 8.3: her sürümde **387 test / 1074 assertion**, başarılı." PR açıklaması ise "her sürümde 393 test / 1112 assertion" diyor — 6 test / 38 assertion fark. :73 "PHP 7.4 ve 8.3 lint: **39 PHP dosyası**"; `git ls-files '*.php' | wc -l` = 42 ve `.github/scripts/lint-php.sh` vendor/coverage dışındaki TÜM .php dosyalarını tarıyor, yani gerçek sayı 42. :74-83 "Tekrarlanabilir doğrulama kanıtı" listesinde finansal retention entegrasyon kapısı HİÇ geçmiyor, oysa tests/integration/schema-migration.php:165-181 bunu gerçekten test ediyor ve PR açıklaması bunu bir kanıt olarak sayıyor. En açık göstergesi ise :6: "**Durum:** Değişiklikler çalışma ağacında; commit veya push yapılmadı." — bu dosya şu anda bir PR içinde commit'lenmiş halde merge edilmeyi bekliyor. 13-remediation-action-plan.md:24-25 aynı hatayı taşıyor: "origin/development is two commits behind locally. No push is part of this plan unless explicitly requested." ve "The only pre-existing working-tree change is the untracked `docs/analysis/` review set." — yani docs/analysis'in TAKİP EDİLMEMESİ planlanmıştı.
```

**Başarısızlık senaryosu**

Bir sonraki sürümde ekip "regresyon var mı?" diye bakmak için 14:72'deki 387/1074 tabanını referans alır. Gerçek taban 393/1112'dir. 6 testin silinmesi veya atlanması (ör. bir @group exclude'u) fark edilmez, çünkü çıktı 387'ye düştüğünde "dokümandaki sayıya döndük" diye yorumlanır. Aynı şekilde yeni eklenen bir PHP dosyası lint kapsamı dışında kalırsa 39 sayısı bunu maskeler.

**Etki**

Kapanış durumunun TEK yetkili kaynağı olarak gösterilen doküman (13:409-412 açıkça "The current evidence-backed implementation status is maintained in 14-remediation-progress.md" diyor), PR'ın kendisiyle çelişen sayılar taşıyor. Bir inceleyici hangi test sayısının doğru olduğunu bilemez; "387 mi 393 mü" sorusunun cevabı yoksa "bu sayılar gerçekten çalıştırıldı mı" güveni de zayıflar. ":6"nın yanlışlığı ayrıca dokümanın merge öncesi hiç gözden geçirilmediğini kanıtlıyor — yani içindeki diğer iddiaların da PR'ın son hâline karşı yeniden doğrulanmadığını.

**Öneri**

1) :6'yı sil veya "Merge edildi: PR #3" olarak güncelle; 13:23-25'teki dal/push anlatısını da kaldır (bunlar süreç notu, kalıcı doküman değil). 2) Test/assertion/dosya sayılarını dokümandan tamamen çıkar ve yerine CI'nin ürettiği bir artefakta bağlantı ver — elle yazılan sayı kaçınılmaz olarak bayatlar. Alternatif olarak `.github/scripts/check-doc-counts.js` ekleyip `phpunit --list-tests` çıktısı ile doküman sayısını CI'da eşle. 3) :74-83 kanıt listesine retention entegrasyon kapısını ekle. 4) Dokümanın başına `**Doğrulandığı commit:** <SHA>` alanı koy ve her güncellemede zorunlu kıl.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 14-remediation-progress.md merge öncesi güncellenmemiş: :72 "387 test / 1074 assertion" ve :73 "39 PHP dosyası" PR gövdesindeki 393/1112 ile ve `git ls-files '*.php'`=42 + lint-php.sh'ın kapsam kuralıyla çelişiyor; :70-83 kanıt listesi PR'ın kanıt saydığı finansal retention entegrasyon kapısını (tests/integration/schema-migration.php:167-206) içermiyor ve :50 retention'ı hâlâ "kalan işletme kararı" gösteriyor; :6 "commit veya push yapılmadı" derken dosya e6975d9'da commit'lenmiş durumda; 13-remediation-action-plan.md:24-25 aynı geçici süreç anlatısını (untracked docs/analysis, push yok) kalıcı doküman içinde taşıyor. Ancak "tek yetkili kaynak" atfı 13:409-412'de değil 13:393-396'da; ve docs/analysis release paketine girmediği için (build-release.sh:49-56) etki dahili ekip/regresyon-tabanı yanlış okumasıyla sınırlı.
- Gerekçe: Çekirdek iddianın tamamı dosyalardan doğrulandı: 14-remediation-progress.md:72'de 387/1074, :73'te 39 PHP dosyası yazıyor; PR gövdesi (gh pr view 3) "her sürümde 393 test / 1112 assertion" diyor ve "finansal retention: geçti" satırını doğrulama kanıtı olarak sayıyor; `git ls-files '*.php' | wc -l` = 42 ve lint-php.sh vendor/ + tests/coverage/ dışındaki TÜM .php dosyalarını `find` ile tarıyor (yani gerçek sayı >= 42, asla 39 değil); :6 hâlâ "Değişiklikler çalışma ağacında; commit veya push yapılmadı" diyor oysa dosya e6975d9 commit'inde takip ediliyor ve PR'ın parçası (git diff --numstat: 100 satır eklendi); 13-remediation-action-plan.md:24-25 gerçekten "origin/development is two commits behind locally..." ve "The only pre-existing working-tree change is the untracked `docs/analysis/` review set." diyor. Kanıt listesinde (:70-83) retention entegrasyon kapısı geçmiyor; dahası :50 R02'nin kalan ölçütü olarak "Finansal saklama süresi işletme kararı" diyor, oysa tests/integration/schema-migration.php:165-206 retention zamanlama/silme davranışını gerçekten test ediyor — yani staleness iddia edilenden bir tık daha geniş.

Düzeltilmesi gereken iki detay: (1) Konum referansı "13-remediation-action-plan.md:409" yanlış. Dosya 480 satır ve "The current evidence-backed implementation status is maintained in 14-remediation-progress.md" cümlesi 393-396 satırlarında (bölüm başlığı ":391 ## 7. Finding coverage and closure ledger"); 409-412 satırları "Coverage is mandatory..." tablosunun içinde ve alakasız. (2) Severity: docs/analysis release ZIP'ine dahil edilmiyor (.github/scripts/build-release.sh:49-56 yalnız API-REFERENCE/ARCHITECTURE/CONFIGURATION/DEVELOPER-GUIDE/USER-GUIDE dosyalarını paketliyor), yani son kullanıcıya sızmayan tamamen dahili bir inceleme artefaktı; regresyon-taban yanlış okuma riski gerçek ama etki kapsamı ekip içiyle sınırlı — high değil medium.

Ayrıca doğrulayamadığım nokta: bu makinede PHP kurulu değil (`php: command not found`), bu yüzden 393/1112'nin mi 387/1074'ün mü doğru sayı olduğunu bağımsız üretemedim — ancak iddia "doküman PR ile çelişiyor" demek olduğu için bu, çelişkinin varlığını değiştirmiyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 14-remediation-progress.md, yazıldığı commit'te (e6975d9) donmuş ve sonraki dört commit'e karşı hiç yeniden doğrulanmamış: (a) :72 "387 test / 1074 assertion" — PR gövdesi 393/1112 diyor ve arada `tests/unit/NicePayAdminOperationsTest.php`, `NicePayBlocksIntegrationTest.php`, `NicePayUrlPolicyTest.php` değişmiş; (b) :73 "PHP 7.4 ve 8.3 lint: 39 PHP dosyası" — iki yönden yanlış: lint job'ı `tests.yml:83-85` uyarınca yalnız PHP 8.2'de çalışıyor, ve `lint-php.sh:10-17` node_modules'ü hariç tutmadığı için `npm ci`den (tests.yml:98-99) sonra çalışan gerçek sayı 42 değil 43'tür (fazladan: node_modules/flatted/php/flatted.php) — bu aynı zamanda lint kapsamının bağımlılık ağacına taştığını gösteren ayrı bir kusurdur; (c) :74-83 kanıt listesi, `tests/integration/schema-migration.php:167-181`'de gerçekten test edilen ve PR gövdesinde kanıt sayılan finansal retention kapısını atlıyor; (d) :6 "commit veya push yapılmadı" ve 13:24-25'teki dal/push anlatısı, dosyanın commit'lenmiş halde PR içinde bulunmasıyla doğrudan çelişiyor (13:25 docs/analysis'in takip edilmeyeceğini söylerken set commit'lenmiş). Dosyayı "kapanış durumunun tek yetkili kaynağı" ilan eden cümle 13:392-393'tedir (iddiadaki 409-412 değil). Etki inceleyici güveni ve doküman tutarlılığıyla sınırlıdır: hiçbir kod yolu, CI adımı veya araç bu sayıları okumaz (`grep -rn "14-remediation-progress"` yalnızca 13:394'teki markdown linkini döndürür), dolayısıyla "ekip 387'yi regresyon tabanı alır" senaryosu otomatik olarak üretilemez, yalnızca elle yanlış dosyaya bakılırsa gerçekleşir.
- Gerekçe: Her bir olgusal iddiayı dosyalardan tek tek doğruladım ve ÇEKİRDEK TAMAMEN DOĞRU. Dört ayrı bayatlama noktası gerçek:

(1) 14:72 "387 test / 1074 assertion" vs PR gövdesi "393 test / 1112 assertion" — `gh pr view 3` çıktısıyla birebir doğrulandı. Üstelik bayatlamanın MEKANİZMASINI da bulup teyit ettim: doküman e6975d9'da yazıldı (`git log -- docs/analysis/14-remediation-progress.md` yalnızca e6975d9 döndürüyor), ama sonraki 4 commit'te `tests/unit/NicePayAdminOperationsTest.php`, `NicePayBlocksIntegrationTest.php` ve `NicePayUrlPolicyTest.php` değişti. Yani 6 test / 38 assertion farkı gerçek bir kod farkı; doküman ilk commit'te donmuş.
(2) 14:73 "39 PHP dosyası" — yanlış, doğrulandı.
(3) 14:74-83 kanıt listesinde finansal retention kapısı geçmiyor; `tests/integration/schema-migration.php:167-181` bunu gerçekten test ediyor ve PR gövdesi "...ve finansal retention: geçti" diyerek kanıt sayıyor.
(4) 14:6 "commit veya push yapılmadı" ve 13:24-25 dal/push anlatısı birebir doğrulandı — ve 13:25'in "The only pre-existing working-tree change is the untracked `docs/analysis/` review set" ifadesi özellikle çarpıcı: `git ls-tree origin/main docs/` çıktısında docs/analysis yok, yani takip edilmemesi planlanan set PR ile commit'lendi.

Ayrıca iddianın LEHİNE ek bir kanıt buldum: 14:73 "PHP 7.4 ve 8.3 lint" diyor ama `.github/workflows/tests.yml:74-111` içindeki `lint` job'ı TEK sürümde, `php-version: '8.2'` ile çalışıyor. Yani satır 73 hem dosya sayısında hem sürüm matrisinde yanlış.

DÜZELTTİĞİM İKİ DETAY:
(a) İddia "gerçek sayı 42" diyor (`git ls-files '*.php' | wc -l` = 42). Ama `lint-php.sh:11-16` git'i değil `find`'ı kullanıyor ve yalnızca `vendor/` ile `tests/coverage/` hariç tutuyor — `node_modules/` hariç DEĞİL. `tests.yml:98-99` `npm ci --ignore-scripts` adımını `composer quality` (satır 110-111) ADIMINDAN ÖNCE çalıştırıyor. Lokal `find` çıktısı 43 ve fazladan dosya `node_modules/flatted/php/flatted.php`. Yani CI'nin bastığı gerçek sayı 42 değil 43'tür ve bu, lint script'inin kapsamının aslında istenmeyen biçimde node_modules'e taştığını gösteriyor — iddianın kendi düzeltmesi de yanlış.
(b) Konum hatası: "13:409-412 açıkça 'The current evidence-backed implementation status is maintained in 14-remediation-progress.md' diyor". O cümle 13:392-393'te. 13:409-412 aslında bulgu ailesi/sahip paket tablosunun satırları (`| Testing | TESTS-01…37 | ... |`). Alıntı doğru, satır referansı yanlış.

SEVERITY ABARTILMIŞ (high -> medium). Sonuç/istismar merceğinden:
- Çalışma zamanı, güvenlik veya ödeme etkisi sıfır. Bu dosya hiçbir kod yolundan okunmuyor; `grep -rn "14-remediation-progress"` yalnızca 13:394'te tek bir markdown linki döndürüyor. Hiçbir CI adımı bu sayıları tüketmiyor.
- Öne sürülen başarısızlık senaryosu ("ekip 387'yi regresyon tabanı alır, 6 testin silinmesi maskelenir") TAM OLARAK ÜRETİLEBİLİR DEĞİL: taban zaten CI'nın kendi çıktısı; docs/analysis/ altındaki Türkçe bir analiz metnini regresyon tabanı olarak okuyacak otomatik ya da belgelenmiş bir süreç yok. Senaryo "birisi yanlış dosyaya bakarsa" varsayımına dayanıyor, somut bir HTTP isteği/rol/girdi/zamanlama zinciri değil.
- Buna karşılık etki de sıfır değil: 13:392-393 bu dosyayı kapanış durumunun tek yetkili kaynağı ilan ediyor ve dosya PR'ın kendisiyle DÖRT ayrı noktada (test sayısı, dosya sayısı, lint sürüm matrisi, commit durumu) çelişiyor. İnceleyici güveni için gerçek ve ucuza düzeltilebilir bir kusur. Bu yüzden refute değil, medium.

---

### META-007 — "Blocks uyumluluğu beyan edilmiyor" ifadesi güvenli bir duruş gibi sunuluyor, ama Blocks adaptörü koşulsuz kaydediliyor ve sertifikasyonsuz Blocks checkout'ta gerçekten para alıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | misleading-safety-claim |
| **Konum** | [readme.txt:69](../../../readme.txt#L69) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```text
readme.txt:69 tacire şunu söylüyor: "A Checkout Blocks adapter is included, but Blocks compatibility is not declared until the real browser, popup, mobile, and accessibility certification matrix passes." 14-remediation-progress.md:58 ve :92-93 aynı çerçeveyi kuruyor: "Cart/Checkout Blocks uyumluluk beyanı bu kanıtlar tamamlanana kadar bilinçli olarak yok." Ancak kayıt yolunda hiçbir kapı yok: nicepay-payment-gateway.php:96 `add_action( 'woocommerce_blocks_loaded', array( $this, 'init_woocommerce_blocks' ) );` ve :312-319 yalnızca `AbstractPaymentMethodType` sınıfının varlığını kontrol edip `woocommerce_blocks_payment_method_type_registration`'a bağlanıyor; ayar, feature flag veya sertifikasyon kontrolü yok. includes/class-nicepay-blocks-integration.php:33-35 `is_active()` yalnızca `$this->gateway->is_available()`'a bakıyor. Yani gateway etkinleştirilmiş her Blocks mağazasında NicePay render edilir ve `process_payment()` üzerinden gerçek para alır. Beyan edilmemiş olması yalnızca WooCommerce → Ayarlar → Gelişmiş → Özellikler ekranında eklentinin "uyumsuz" görünmesine yol açar. Ek bir tutarsızlık: :69 `'supports' => array( 'products' )` — gateway `refunds` desteklediği halde Blocks veri sözleşmesinde bildirilmiyor.
```

**Başarısızlık senaryosu**

Bir tacir WooCommerce 9+ ile Blocks checkout kullanıyor. readme.txt:69'u okur, "Blocks uyumluluğu beyan edilmemiş, demek ki Blocks'ta çalışmıyor, klasik checkout'a geçmem gerekmiyor" diye düşünür ve gateway'i etkinleştirir. NicePay Blocks checkout'ta görünür, müşteriler öder. Aynı anda WooCommerce → Ayarlar → Gelişmiş → Özellikler ekranı eklentiyi "Cart/Checkout blocks ile uyumsuz" diye listeler. Tacir hangi bilgiye güveneceğini bilemez; bir sorun çıktığında (popup engelleme, mobil dönüş) hem eklenti hem WooCommerce "desteklenmiyor" der ve destek yolu yoktur.

**Etki**

Doküman, tacire "Blocks tarafı henüz sertifikalı değil, o yüzden beyan etmiyoruz" diyerek bir güvence hissi veriyor; tacir bunu "Blocks checkout'ta görünmeyecek" diye okuyabilir. Gerçekte tam tersi: sertifikasyonsuz yüzey CANLI ve para alıyor; beyan eksikliği yalnızca WooCommerce'in tacire eklentiyi uyumsuz göstermesine, dolayısıyla gereksiz destek yüküne ve bazı kurulumlarda Blocks özelliğinin kapatılmasına yol açıyor. Bu, iki dünyanın da kötü yanı ve dokümantasyon bunu iyi bir şeymiş gibi anlatıyor.

**Öneri**

İkisinden birini seç ve dokümanı ona göre yaz: (a) Blocks adaptörünü varsayılan KAPALI bir ayarın veya `nicepay_enable_checkout_blocks` filtresinin arkasına al; readme.txt:69'u "Blocks adaptörü mevcut ama varsayılan kapalıdır; etkinleştirmek sertifikasyonsuz bir yüzeyi açar" diye netleştir. (b) Kayıt zaten koşulsuz olduğu için gerçek durumu kabul et: readme.txt:69'u "Checkout Blocks üzerinde ödeme yöntemi görünür ve çalışır; ancak tarayıcı/mobil/erişilebilirlik matrisi tamamlanmadığından WooCommerce'e resmi uyumluluk beyanı yapılmamıştır — WooCommerce bu nedenle eklentiyi uyumsuz listeleyebilir" olarak yaz ve aynı cümleyi 14-remediation-progress.md:58 ile docs/USER-GUIDE.md'ye taşı. Ayrıca `get_payment_method_data()`'da `'supports' => array( 'products', 'refunds' )` yap.

---

### META-008 — Aynı doküman setinde şiddet renk kodu iki farklı anlamda kullanılıyor: 08 ve 10'da 🔴 = HIGH, geri kalan her yerde 🔴 = CRITICAL

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | internal-inconsistency |
| **Konum** | [docs/analysis/10-documentation.md:93](../../../docs/analysis/10-documentation.md#L93) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
Global sözlük 01-findings-index.md:27-33 ve 00-executive-summary.md:100-108'de tanımlı: 🔴 Critical / 🟠 High / 🟡 Medium / 🔵 Low / ⚪ Enhancement. 04-security.md, 06-wordpress-woocommerce-platform.md ve 09-testing.md bu sözlüğe uyuyor (04: 1×'🔴 CRITICAL', 4×'🟠 HIGH', 9×'🟡 MEDIUM', 14×'🔵 LOW'). Ancak 10-documentation.md:93 kendi yerel sözlüğünü ilan ediyor: "**Severity legend:** `🔴 HIGH` · `🟠 MEDIUM` · `🟡 LOW`" ve :100 "#### DOCS-001 · 🔴 HIGH — ..." şeklinde kullanıyor. 08-internationalization.md ise HİÇBİR sözlük ilan etmeden aynı kaymayı yapıyor: :108 "#### I18N-01 · 🔴 HIGH · `i18n-completeness`", :215 "#### I18N-03 · 🔴 HIGH", ve ⚪ yerine 🔵'yı ENHANCEMENT için kullanıyor (2×'🔵 ENHANCEMENT'). Şiddet DEĞERLERİ index ile tutarlı (DOCS: 2 high / 20 medium / 17 low — index sayımıyla birebir eşleşiyor); tutarsız olan yalnızca renk kodu.
```

**Başarısızlık senaryosu**

Yayın öncesi triage toplantısında ekip "kalan tüm 🔴'ları kapatalım" der. Biri 08-internationalization.md'yi tarar, I18N-01/02/03'ü 🔴 gördüğü için kritik listesine ekler ve bunlara mühendis atar; aynı zamanda gerçek kritik olan ve 12-path-to-first-class.md'de `CRITICAL` etiketli (renksiz, backtick'li) EXCELLENCE-01/02 gözden kaçar çünkü orada kırmızı daire yoktur.

**Etki**

01-findings-index.md'yi okuyup 🔴'yı "para kaybı / güvenlik ihlali" olarak içselleştiren bir okuyucu, 10-documentation.md veya 08-internationalization.md'ye geçtiğinde 5 adet 🔴 görüyor ve 5 kritik dokümantasyon/i18n bulgusu olduğunu sanıyor; gerçekte hiçbiri kritik değil ve index bunları high olarak sayıyor. 08 hiç uyarı vermediği için oradaki yanılgı tamamen sessiz. Öncelik sıralaması yapan biri yanlış üç maddeyi öne alır.

**Öneri**

Tek bir sözlüğe indir. En düşük maliyetli düzeltme: 08 ve 10'daki emojileri global sözlüğe çevir (🔴 HIGH → 🟠 HIGH, 🟠 MEDIUM → 🟡 MEDIUM, 🟡 LOW → 🔵 LOW, 🔵 ENHANCEMENT → ⚪ ENHANCEMENT) ve 10:93'teki yerel sözlüğü sil. Alternatif olarak emojileri tamamen bırak, yalnızca metin etiketi kullan (`CRITICAL`/`HIGH`/...) — 12-path-to-first-class.md zaten bunu yapıyor ve hiç tutarsızlık üretmiyor. Sonrasında bir CI kontrolü ile 'X EMOJI + severity kelimesi' eşleşmelerini global tabloya karşı doğrula.

---

### META-009 — 16.347 satırlık analiz seti sahipsiz, tarihsiz, hiçbir giriş noktasından bağlantısız ve hiçbir CI kapısıyla korunmuyor — bakım planı yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | maintainability |
| **Konum** | [docs/analysis/13-remediation-action-plan.md:3](../../../docs/analysis/13-remediation-action-plan.md#L3) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
Yalnızca iki dosyada tarih damgası var: 13-remediation-action-plan.md:3 "**Prepared:** 2026-08-19" ve 14-remediation-progress.md:3 "**Son güncelleme:** 2026-08-20". 00-12 (14.500+ satır) tarihsiz. Hiçbir dosyada sahip, gözden geçirme periyodu veya geçerlilik süresi yok. `docs/analysis/` klasöründe README/index dosyası yok (`ls docs/analysis/` → yalnız 00-14). Depo genelinde grep sonucu: docs/analysis'e yapılan tek referanslar dışlama kuralları (.gitattributes:27, smoke-check-artifact.sh:50, deploy-wordpress-org.yml:177); README.md, CONTRIBUTING.md, CHANGELOG.md veya docs/*.md içinden HİÇBİR bağlantı yok. .github/CODEOWNERS tek satır: `* @cemililik` — analiz setine özel bir sahip yok. CI'da markdown link kontrolü, doküman tazeliği kapısı veya banner kontrolü yok (`composer quality` = lint:php + lint:js + check-version; `npm run quality` = eslint + css + node --test). 13-remediation-action-plan.md:25 ise bu setin aslında takip edilmemesi gerektiğini söylüyor: "The only pre-existing working-tree change is the untracked `docs/analysis/` review set."
```

**Başarısızlık senaryosu**

Altı ay sonra ürün ekibi kredi kartı taksitlerini (SelectQuota) ekler. Kimse docs/analysis'i güncellemez çünkü CODEOWNERS'da o klasörü işaret eden kimse yok ve CI hiçbir şey söylemez. Bir yıl sonra yeni bir bakımcı 12-path-to-first-class.md:852'yi "yapılacaklar" sanıp `cart_checkout_blocks` beyan eder (META-004'teki senaryo) ya da 07-architecture-and-code-quality.md:3'ün "ten production PHP files (3,863 LOC)" ifadesine göre mimari kararlar alır — oysa dosya sayısı 17, satır sayısı 9.100'dür.

**Etki**

Kodun her commit'inde bu 16.347 satır biraz daha bayatlıyor ve bayatladığını kimse fark etmiyor: ne bir sahip, ne bir gözden geçirme tarihi, ne bir CI uyarısı var. Aynı zamanda keşfedilebilir de değil — hiçbir giriş noktasından bağlantılı olmadığı için ne yeni katkıcı bulur ne de kasten okunur; yalnızca kazara veya bir saldırgan tarafından bulunur. Sonuç: sürekli artan bir bakım borcu ve sıfır fayda döngüsü.

**Öneri**

1) `docs/analysis/README.md` ekle: setin ne olduğu, hangi commit'i anlattığı, hangi dosyanın hâlâ canlı (13, 14) hangisinin arşiv (00-12) olduğu, ve okuma sırası. 2) Her dosyanın başına `**Sahip:** @cemililik · **Kapsanan commit:** 5855db1 · **Son doğrulama:** YYYY-MM-DD · **Durum:** ARŞİV|CANLI` bloğu ekle. 3) `.github/CODEOWNERS`'a `docs/analysis/ @cemililik` satırı ekle ki değişiklikler zorunlu incelemeye girsin. 4) CI'ya iki ucuz kapı ekle: markdown link/anchor kontrolü (lychee veya kendi node scriptin — zaten 500+ iç bağlantı var) ve "CANLI" işaretli dosyalarda `Son doğrulama` tarihinin 90 günden eski olmaması kontrolü. 5) Alternatif ve en temiz yol: 00-12'yi depodan çıkarıp bir GitHub Release asset'i veya Wiki sayfası yap; yalnızca 13/14 depoda kalsın.

---

### META-010 — Dil karışıklığı: analiz seti 00–12 İngilizce, 13–14 Türkçe, docs/WORDPRESS-ORG-RELEASE.md Türkçe — oysa dağıtılan tüm dokümanlar İngilizce

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | consistency |
| **Konum** | [docs/analysis/14-remediation-progress.md:1](../../../docs/analysis/14-remediation-progress.md#L1) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
00-executive-summary.md:1 "# NicePay Payment Gateway — Comprehensive Review: Executive Summary" (İngilizce, 00-12'nin tamamı böyle). 13-remediation-action-plan.md:1 İngilizce başlıyor ama 14-remediation-progress.md:1 "# NicePay düzeltme programı — uygulama ve kapanış durumu" ve gövdesinin tamamı Türkçe ("DÜZELTİLDİ", "KISMİ", "DIŞ BAĞIMLI", "PLANLANDI" durum etiketleri dahil). docs/WORDPRESS-ORG-RELEASE.md de Türkçe (:87 "Başvuru ve her sürüm öncesi resmi validator kullanılmalıdır", :179 "- [ ] `readme.txt` resmi validator'dan hatasız geçti.") — buna karşılık release ZIP'ine giren beş doküman (API-REFERENCE, ARCHITECTURE, CONFIGURATION, DEVELOPER-GUIDE, USER-GUIDE, build-release.sh:50) ile README.md, CHANGELOG.md, SECURITY.md, CONTRIBUTING.md, readme.txt tamamen İngilizce.
```

**Başarısızlık senaryosu**

WordPress.org yayın sürecini ikinci bir kişi devralır. docs/WORDPRESS-ORG-RELEASE.md'yi açar, Türkçe olduğu için :179'daki "readme.txt resmi validator'dan hatasız geçti" kontrol maddesini atlar veya yanlış yorumlar, ve dry-run yerine doğrudan `publish` modunu tetikler. Ya da bir güvenlik denetçisi 14-remediation-progress.md'yi okuyamaz ve "KISMİ" olan 7 paketi "tamamlanmış" varsayar.

**Etki**

Durum etiketlerinin ("DÜZELTİLDİ"/"KISMİ") 13-remediation-action-plan.md'de tanımlanan İngilizce kapanış durumlarına (FIXED / SUPERSEDED / VERIFIED_NOT_APPLICABLE / EXTERNAL_BLOCKED, :14-17) eşleşmesi hiçbir yerde belirtilmemiş; okuyucu "KISMİ"nin hangi resmi duruma karşılık geldiğini çıkarsamak zorunda (aslında hiçbirine — plan "kısmi" diye bir kapanış durumu tanımlamıyor). Türkçe bilmeyen bir katkıcı veya WordPress.org inceleme ekibi üyesi, kapanış durumunun tek yetkili kaynağını (14) ve WP.org yayın prosedürünü (WORDPRESS-ORG-RELEASE.md) okuyamaz.

**Öneri**

Tek bir doküman dilinde karar ver — depo geri kalanı İngilizce olduğu için İngilizce doğal seçim. 14-remediation-progress.md'yi İngilizceye çevir ve durum etiketlerini planın kendi sözlüğüne bağla (`FIXED` / `PARTIAL (evidence pending: ...)` / `EXTERNAL_BLOCKED` / `PLANNED`), ayrıca 13:14-17'ye `PARTIAL` durumunu ekle çünkü pratikte kullanılıyor ama tanımlı değil. docs/WORDPRESS-ORG-RELEASE.md'yi de İngilizceye çevir. Türkçe bir özet isteniyorsa `docs/analysis/14-remediation-progress.tr.md` gibi açıkça işaretlenmiş bir çeviri olarak tut.

---

### META-011 — "WordPress.org resmi readme.txt validator: hata/uyarı yok" iddiasının hiçbir kanıtı yok; deponun kendi kontrol listesindeki madde işaretlenmemiş ve readme.txt `Contributors:` başlığından yoksun

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | unevidenced-claim |
| **Konum** | [docs/WORDPRESS-ORG-RELEASE.md:179](../../../docs/WORDPRESS-ORG-RELEASE.md#L179) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
PR açıklaması "WordPress.org resmi readme.txt validator: hata/uyarı yok" diyor. Deponun kendi prosedür dokümanı ise bunu tamamlanmamış bir görev olarak listeliyor: docs/WORDPRESS-ORG-RELEASE.md:179 "- [ ] `readme.txt` resmi validator'dan hatasız geçti." — kutu BOŞ. :87-88 validator'ı manuel bir adım olarak tarif ediyor ("Başvuru ve her sürüm öncesi resmi validator kullanılmalıdır: https://wordpress.org/plugins/developers/readme-validator/"), yani otomasyon yok ve CI'da readme.txt doğrulayan hiçbir adım yok (deploy-wordpress-org.yml içinde readme validator çağrısı yok). Ayrıca readme.txt başlık bloğu (satır 1-8) `Contributors:` ve `Donate link:` satırlarını içermiyor — WP.org validator'ının uyarı ürettiği bilinen alanlar; "Tested up to: 7.0" (:4) ise henüz yayımlanmamış olabilecek bir WordPress sürümüne işaret ediyor ve validator bunu da uyarı olarak işaretleyebilir.
```

**Başarısızlık senaryosu**

Ekip WP.org'a başvurur. Plugin Review ekibi readme.txt'yi validator'dan geçirir ve `Contributors` eksikliği + kısa açıklama/etiket uyarılarını raporlar. Başvuru revizyon isteğiyle geri döner; ekip PR açıklamasındaki "hata/uyarı yok" iddiasına güvendiği için hazırlıksız yakalanır ve yayın döngüsü haftalarca uzar.

**Etki**

Yayın hazırlığının en dışa dönük kanıtlarından biri doğrulanamaz durumda ve deponun kendi belgesiyle çelişiyor. "Hata/uyarı yok" ifadesi bir inceleyicinin bu adımı atlamasına neden olur; gerçekte adım hiç çalıştırılmamış olabilir. WP.org gönderiminde reddedilme veya gecikme riski.

**Öneri**

1) İddiayı kanıtla veya kaldır: validator çıktısının ekran görüntüsü/metnini PR'a ekle ve docs/WORDPRESS-ORG-RELEASE.md:179 kutusunu tarih + sonuçla işaretle. 2) Manuel adımı otomatikleştir: `.github/scripts/check-readme-txt.js` ekleyip başlık alanlarını (Contributors, Tags ≤5, Requires at least, Tested up to, Stable tag, Requires PHP, License, kısa açıklama ≤150 karakter) zorunlu kıl ve `composer quality`/`npm run quality` içine bağla; deploy-wordpress-org.yml `prepare` job'ına da ekle. 3) readme.txt:1-8'e `Contributors: cemililik` satırını ekle. 4) `Tested up to:` değerini gerçekten test edilmiş en yüksek WordPress sürümüyle eşle ve bunu WooCommerce smoke matrisinden türet.

---

### META-012 — 12-path-to-first-class.md'nin "Horizon" yol haritası ölçülebilir kabul kriteri, sahip veya tanım taşımıyor — plan dokümanının kendi standardının çok altında

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | unmeasurable-goals |
| **Konum** | [docs/analysis/12-path-to-first-class.md:841](../../../docs/analysis/12-path-to-first-class.md#L841) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
12-path-to-first-class.md:841-895 üç "Horizon" tablosu içeriyor. Satırlar yalnızca ID + prozaik iş tanımı: :852 "Blocks payment-method registration; `needs_setup()`; declare `cart_checkout_blocks` + `custom_order_tables`", :860 "`ignore_user_abort( true )`; split connect/read timeouts to the spec's 5s/30s; interstitial". Hiçbir satırda kabul ölçütü, ölçülebilir eşik, sahip veya tarih yok. Karşılaştırma: 13-remediation-action-plan.md aynı işi paket başına "**Acceptance gate**" ile tanımlıyor ve test edilebilir (ör. :158 "A valid cheap-payment callback with another order's Moid causes zero approval calls and no order mutation.", :180 "Two concurrent callbacks yield exactly one owner of the `approving` transition."). Ayrıca 12'nin başlığı hedefi tanımlıyor ("a perfect, first-class experience — excellent in UI/UX, in ease of use and clarity, and in functionality") ama bu hedefin ne zaman ulaşıldığını söyleyecek tek bir metrik yok; :774 "The 20% that yields 80% of the quality jump" bölümü de "quality delta ÷ effort" ile sıralandığını söylüyor ama ne quality delta ne effort sayısallaştırılmış (yalnız S/M/L etiketleri). Doküman sonundaki not ise tüm 40 bulgunun `PLAUSIBLE` olduğunu kabul ediyor: "*All forty findings carry verdict `PLAUSIBLE` and are marked \"needs confirmation\"*".
```

**Başarısızlık senaryosu**

Ekip Horizon 1'i planlar. "EXCELLENCE-33 | Totals strip over the current filter, per-method breakdown, CSV export" maddesi için bir geliştirici toplam şeridi ekler ve maddeyi kapatır. İkinci bir kişi "CSV export KST zaman damgalarıyla mı? Filtre kapsamında toplam mı yoksa sayfa kapsamında mı?" diye sorar — cevap dokümanda yok, çünkü kabul kriteri yok. Madde iki farklı biçimde "tamamlandı" sayılır ve gerçek gereksinim kaybolur.

**Etki**

"Birinci sınıf" hedefi tanımsız kaldığı için ne ulaşıldığını ne de ilerlemenin ölçüldüğünü söylemek mümkün değil. 12'nin Horizon-0 listesi aynı zamanda üretim önkoşulu ilan edilmiş ("Do not take live money until all of these are done"), yani ölçülemeyen bir liste yayın kapısı görevi görüyor — ve META-004'te gösterildiği gibi zaten güncel kararlarla çelişiyor. 40 maddenin tamamı doğrulanmamış (`PLAUSIBLE`) olduğu halde bir yol haritası gibi sunulması riski büyütüyor.

**Öneri**

Horizon tablolarına iki sütun ekle: `Kabul kriteri` (yürütülebilir/gözlemlenebilir cümle) ve `Kanıt` (test adı veya ekran). Örnek: `EXCELLENCE-33 | Toplam şeridi | Kabul: 500 satırlık fixture'da 'method=CARD + status=paid' filtresi uygulandığında şeritteki toplam, aynı filtreyle çalışan SQL SUM ile birebir eşleşir ve sayfalama değiştiğinde değişmez | Kanıt: NicePayTransactionsAdminTest::test_totals_follow_active_filter`. Ayrıca doküman başına bir uyarı koy: "Bu belgedeki 40 maddenin tamamı `PLAUSIBLE` verdiktine sahiptir ve uygulamadan önce doğrulanmalıdır; Horizon-0 listesi bir yayın kapısı DEĞİL, doğrulanacak hipotez listesidir." 13-remediation-action-plan.md'nin "Acceptance gate" biçimini şablon olarak kullan.

---

### META-013 — DG-01/DG-02 iç karar-kapısı kimlikleri tacire dağıtılan dokümanlara sızmış; tanımlarını içeren tek yetkili kaynak (13-remediation-action-plan.md) ZIP'ten dışlanıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | internal-jargon-leak |
| **Konum** | [docs/USER-GUIDE.md:618](../../../docs/USER-GUIDE.md#L618) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
DG-01/DG-02, yalnızca docs/analysis/13-remediation-action-plan.md:64-70'te tanımlanmış iç karar kapılarıdır ("### DG-01 — NICEPAY protocol target", "### DG-02 — Vendor fixtures and sandbox access"). Bu kimlikler dağıtılan dokümanlarda 16 yerde geçiyor. Bazıları satır içinde kendini tanımlıyor (README.md:7, docs/API-REFERENCE.md:7, docs/DEVELOPER-GUIDE.md:450-451), ancak kullanım noktalarının bir kısmı tanımsız: docs/USER-GUIDE.md:618 "- [ ] Confirm DG-01 legacy-flow provisioning and collect DG-02 fixtures for the methods/refund behavior being certified", docs/USER-GUIDE.md:480/487/499 "certify full/partial behavior for the actual MID under DG-02", docs/CONFIGURATION.md:487 "Treat VBANK as future work requiring DG-01/DG-02 plus a complete implementation.", docs/ARCHITECTURE.md:7. Tanımın bulunduğu 13-remediation-action-plan.md ise .gitattributes:27 ve build-release.sh:41-64 nedeniyle release ZIP'ine hiç girmiyor.
```

**Başarısızlık senaryosu**

Tacir docs/USER-GUIDE.md:610-620'deki go-live kontrol listesini işaretleyerek ilerler. :618'e gelir, "DG-01" ve "DG-02"nin ne olduğunu anlamaz, pakette arama yapar, tanım bulamaz, maddeyi atlar ve canlı moda geçer. NICEPAY'in ilgili MID'i legacy PG-Web v3 için provision etmemişse ilk gerçek ödeme onay aşamasında başarısız olur veya (daha kötüsü) yetkilendirilir ama onaylanamaz ve müşterinin kartında askıda bir tutar kalır.

**Etki**

Bir tacir USER-GUIDE'daki go-live kontrol listesini açtığında "DG-01" ve "DG-02" ile karşılaşıyor; pakette bunları tanımlayan tek dosya yok (README.md pakete giriyor ve tanımı içeriyor, ama kullanıcı kılavuzundaki kontrol listesi README'ye yönlendirmiyor). Sonuç: yapılabilir bir yayın kontrol listesi maddesi, anlamsız bir kod dizisine dönüşüyor ve muhtemelen atlanıyor — oysa bu madde tam olarak "canlı para almadan önce sağlayıcı onayını al" demek.

**Öneri**

Ya kimlikleri dağıtılan dokümanlardan tamamen kaldır ve yerlerine düz İngilizce koy ("written confirmation from NICEPAY that your MID is provisioned for the legacy PG-Web v3 / manual v2.0.8 flow"), ya da docs/USER-GUIDE.md ile docs/CONFIGURATION.md içine kısa bir "Certification gates" bölümü ekleyip DG-01/DG-02'yi yerinde tanımla. En temizi: docs/USER-GUIDE.md:618'i şöyle yaz: "- [ ] NICEPAY'den, MID'inizin legacy PG-Web v3 / manual v2.0.8 akışı için provision edildiğine dair yazılı onay alın ve sertifikalandıracağınız her yöntem/iade davranışı için sanitize edilmiş sandbox fixture'ları toplayın."

---

### META-014 — 489 / 485 / 484 üçlü bulgu sayımı hiçbir sayılabilir artefakta bağlanmıyor; ayrıca 01-findings-index.md'de bir kırık çapa var

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | numbers-hygiene |
| **Konum** | [docs/analysis/00-executive-summary.md:3](../../../docs/analysis/00-executive-summary.md#L3) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
00-executive-summary.md:3 "**489 surviving findings — 14 critical, 66 high, 246 medium, 135 low, 23 enhancement**" diyor; :113 dipnotu ise "the eleven documents record 485 numbered entries against 489 verified findings, and the index table lists 484 rows" diyerek üç farklı sayıyı kabul ediyor. Sayılabilir tek artefakt index: otomatik sayım 484 satır (14+66+246+135+23 = 484) ve dokümanlar tablosundaki (00:196-208) per-doküman toplamları 485 veriyor. "489"un nereden geldiğini gösteren bir liste hiçbir yerde yok — yani 5 bulgu ne index'te ne dimension dokümanlarında ayrı ayrı görülebiliyor. Ayrı olarak, 500+ iç bağlantı içinde tek bir kırık çapa var: 01-findings-index.md:554 `09-testing.md#3-tests-36--the-work-plan-15-tests-to-add-ordered-by-money-risk`; hedef başlık 09-testing.md:887 "## 3. TESTS-36 · ✨ The work plan: 15 tests to add, ordered by money risk" ve emoji nedeniyle GitHub'ın ürettiği slug farklı.
```

**Başarısızlık senaryosu**

Bir denetçi "489 bulgunun kaçı kapandı?" diye sorar. Ekip index'i açar, 484 satır sayar. Denetçi "peki kalan 5?" der. Kimse hangi 5 olduğunu gösteremez, çünkü katlanan girdilerin (UX-117 dışında) listesi yok. Sayının bütünlüğüne dair güven kaybolur ve tüm kapanış iddiaları yeniden sorgulanır.

**Etki**

"489 bulgu" seti tanıtan başlık sayısıdır ve her yerde tekrarlanır (00:3, 01:3, 13:12) ama denetlenebilir değil. Bir inceleyici sayıyı doğrulamak isterse 484 bulur, aradaki 5'i bulamaz. Bu, aksi halde çok titiz olan bir sayım disiplininin tek zayıf noktası ve tüm nicel iddiaların güvenilirliğine gölge düşürüyor. Kırık çapa ise tek bir tıklamanın hedefini kaçırmasına yol açar.

**Öneri**

1) Tek bir sayıya indir ve onu index'ten türet: 484 numaralı kayıt. "489" kullanılacaksa 00:113 dipnotunu genişletip katlanan 5 girdinin tamamını isimleriyle listele (bugün yalnızca UX-117/UX-099 örneği veriliyor). 2) 09-testing.md:887 başlığından `✨` emojisini kaldır (veya başlığı `## 3. TESTS-36 — The work plan: 15 tests to add, ordered by money risk` yap) ve 01-findings-index.md:554'teki bağlantıyı buna göre güncelle. 3) Sayımı scriptle: `grep -c '^| \[' 01-findings-index.md` çıktısını dokümandaki başlık sayısıyla karşılaştıran küçük bir CI adımı, bu sınıf hatayı kalıcı olarak kapatır.

---

### META-015 — "Deterministic allowlist ZIP" iddiası bayt düzeyinde tekrar üretilebilirlik anlamına gelmiyor, ama yayınlanan SHA-256 checksum'ları öyle okunmaya davet ediyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | over-claim |
| **Konum** | [docs/analysis/14-remediation-progress.md:66](../../../docs/analysis/14-remediation-progress.md#L66) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
14-remediation-progress.md:66 R18 için "deterministic allowlist ZIP, checksum" başarısını sayıyor; CHANGELOG.md:16 "Added ... deterministic release packaging, dependency audits, translation checks". Ancak .github/scripts/build-release.sh:67-70 arşivi `zip -q -r "$output_path" "$plugin_slug"` ile üretiyor — `-X` (ekstra alanları at) yok, `SOURCE_DATE_EPOCH` veya mtime normalizasyonu yok, dosya sıralaması `zip -r`'nin dizin gezinme sırasına bırakılmış, ve :46 `cp -R` mtime'ları korumadığı için her build'de o anki zaman damgaları gömülüyor. Buna karşılık .github/workflows/release.yml:63-66 çıktının SHA-256'sını üretip GitHub Release'e yükleniyor ve deploy-wordpress-org.yml:182 ikinci bir checksum üretiyor. Yani aynı tag'den iki build farklı SHA-256 verir. (Not: "deterministic" burada "içerik allowlist ile belirlenir" anlamına da gelebilir — bu okuma savunulabilir, ancak CHANGELOG'daki tek başına "deterministic release packaging" ifadesi bu ayrımı yapmıyor.)
```

**Başarısızlık senaryosu**

Bir kurumsal müşterinin güvenlik ekibi eklentiyi onaylamadan önce provenance doğrulaması yapar: `git checkout v2.0.0 && bash .github/scripts/build-release.sh 2.0.0 out.zip && sha256sum out.zip`. Sonuç, Release sayfasındaki `.sha256` dosyasıyla eşleşmez (zip içindeki mtime'lar farklıdır). Ekip bunu "yayınlanan artefakt kaynak koddan üretilemiyor" diye raporlar ve onay bloke olur.

**Etki**

Yayınlanan checksum, bir kullanıcının indirdiği ZIP'in bozulmadığını doğrulamaya yarar ama kaynaktan tekrar üretilebilirliği KANITLAMAZ. "Deterministic" kelimesi tedarik zinciri bağlamında ikinci anlamı çağrıştırır; bir güvenlik denetçisi tag'i çekip build alır, farklı bir SHA-256 elde eder ve bunu tedarik zinciri uyuşmazlığı olarak raporlayabilir. Kelime kanıtlanmamış bir garanti veriyor.

**Öneri**

İki seçenekten birini yap. (a) İddiayı daralt: 14:66 ve CHANGELOG.md:16'daki "deterministic"i "allowlist-based (yalnızca açıkça listelenen dosyalar paketlenir)" ile değiştir — kod zaten bunu yapıyor ve bu doğru bir ifade. (b) İddiayı gerçekten karşıla: build-release.sh'i tekrar üretilebilir hale getir:

```bash
export TZ=UTC
source_epoch="$(git -C "$repository_root" log -1 --pretty=%ct)"
find "$package_root" -exec touch -h -d "@$source_epoch" {} +
( cd "$temporary_root" && find "$plugin_slug" -print0 | LC_ALL=C sort -z \
    | xargs -0 zip -q -X -D "$output_path" )
```

Sonra CI'da iki kez build alıp SHA-256'ları karşılaştıran bir adım ekle; bu, iddiayı yürütülebilir bir kapıya dönüştürür.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İnceledim: docs/analysis/00–14'ün tamamı (16.347 satır) — 00, 13, 14 satır satır; 01 tamamen + otomatik ID/çapa/sayım denetimi (Python); 02–12 giriş bölümleri, şiddet sözlükleri, yol haritası/appendix bölümleri ve örneklenmiş bulgu gövdeleri (SECURITY-01/02/03, DOCS-001/002, EXCELLENCE-01/02/10/25/40). Otomatik doğrulamalar: (a) index'teki 484 ID'nin hedef dokümanlarda varlığı → 0 eksik; (b) 500+ iç markdown çapası (heading slug + <a id> HTML çapaları dahil) → 1 gerçek kırık; (c) 2.373 `#L` kaynak bağlantısının hedef dosya varlığı → 0 kırık dosya, ama tümü taban commit satırlarına ait; (d) severity emoji/kelime eşleşmelerinin doküman başına sayımı; (e) prefiks başına ID aralığı bütünlüğü (boşluk/çift kayıt). İddia doğrulama: PR açıklamasındaki 8 iddianın 7'sini kodda/CI'da doğruladım (fresh install disabled defaults, KRW+CARD/BANK/CELLPHONE kısıtı, Blocks beyan edilmemesi, WP.org workflow manuel+dry-run varsayılanı, MariaDB 10.11 + WooCommerce 11.0.1 pinleri, retention entegrasyon testi, docs/analysis'in ZIP'ten üçlü dışlanması). 13/14'teki DÜZELTİLDİ iddialarından 10'unu koda karşı örnekledim (atomik claim, offer resolver, opak ID, EUC-KR kaldırma, gateway default, secret render, KRW yuvarlama, CSV sanitize, retention batch, request-scoped timeout) — hepsi doğrulandı.\n\nİnceleyemediklerim ve nedeni: (1) \"393 test / 1112 assertion\" ve \"387/1074\" sayılarını ÇALIŞTIRARAK doğrulayamadım — ortamda `php` ve `composer` binary'si yok (`which php` → not found), yalnızca vendor/bin/phpunit dosyası mevcut. Statik olarak 20 dosyada 264 test metodu saydım; dataProvider genişlemeleriyle 393'e ulaşması makul, ancak iki sayıdan hangisinin doğru olduğunu kanıtlayamadım — bu yüzden META-005'i \"iki kaynak çelişiyor\" olarak raporladım, \"sayı yanlış\" olarak değil. (2) WordPress.org resmi readme.txt validator'ını çalıştıramadım (ağ erişimi yok); META-011'i `Contributors` eksikliği ve deponun kendi işaretlenmemiş kontrol maddesi üzerinden, `medium` güvenle raporladım. (3) 02–12'nin ~14.000 satırlık bulgu gövdelerinin her birini tek tek koda karşı yeniden doğrulamadım — bunlar zaten taban commit'e ait olduğu için ayrı ayrı doğrulukları bu boyutun sorusu değil; sorulan şey setin bütünlüğü ve iddia-gerçek uyumu idi. (4) 484 bulgunun her birinin kapanış durumunu tek tek doğrulayamadım — çünkü META-003'te raporladığım gibi böyle bir eşleme artefaktı hiç üretilmemiş; örnekleme ile en riskli 14 kritik + seçilmiş paket iddiaları üzerinden ilerledim.

**Açık sorular**

- docs/analysis 00–12 kasten mi merge edildi, yoksa 13-remediation-action-plan.md:25'te "untracked review set" olarak tanımlandığı için yanlışlıkla mı commit'lendi? Cevap, META-001 için (a) çıkar (b) banla seçeneklerinden hangisinin doğru olduğunu belirler.
- Nisan 2026'daki 2.0.0 gerçekten üçüncü taraflara dağıtıldı mı (WP.org, doğrudan indirme, müşteri kurulumu)? Dağıtılmadıysa META-002'nin sömürü-yayını boyutu düşer ama sürüm numarası çakışması ve CHANGELOG tarihinin ezilmesi yine de düzeltilmeli.
- "393 test / 1112 assertion" hangi commit'te ve hangi PHP sürümlerinde ölçüldü? CI çıktısına bağlanabilirse META-005 tek bir doküman güncellemesiyle kapanır.
- Checkout Blocks adaptörünün koşulsuz kaydı bilinçli bir karar mı? Eğer "sertifikasyon bitene kadar kapalı kalsın" niyeti varsa META-007 bir kod hatası; "çalışsın ama resmi beyan yapmayalım" niyeti varsa yalnızca dokümantasyon düzeltmesi.
- Finansal saklama süresi kararı (13-remediation-action-plan.md DG-03) verildi mi? Verilmediyse CONFIGURATION.md:66-77'deki tüm prosedür hâlâ hipotetik ve META-006'daki CSV kapağı uyarısı ilk gerçek kullanımdan önce eklenmeli.
- docs/analysis için kalıcı bir sahip ve gözden geçirme kadansı atanacak mı? Atanmayacaksa 00–12'yi depodan çıkarmak, banner eklemekten daha düşük toplam maliyetli olur.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 15 |

## Öncelikli aksiyon listesi

1. **META-001** — İki adımdan birini seç: (a) 00–12'yi PR'dan çıkar ve ayrı bir `analysis/` deposunda veya bir GitHub Discussion/Wiki sayfasında tut; ya da (b) her dokümanın İLK satırına makine-üretilebilir bir band ekle ve CI'da varlığını zorunlu kıl. Örnek band:

```markdown
> **ARŞİV — GEÇERSİZ ANALİZ.** Bu doküman `5855db1` (2026-08-19) taban ağacını anlatır.
> Bulguların çoğu PR #3 ile kapatılmıştır; güncel du
2. **META-002** — 1) Sürümü derhal 2.1.0'a (veya 3.0.0) çıkar: nicepay-payment-gateway.php:7,22, readme.txt:6, package.json, CHANGELOG.md yeni başlık; `check-version.js` zaten hepsini eşleyecektir. 2) main'deki `## [2.0.0] - 2026-04-03` girdisini GERİ getir ve yeni girdiyi onun üstüne ayrı bir başlık olarak ekle. 3) readme.txt `== Upgrade Notice ==` altına açık bir güvenlik ifadesi koy: "2.0.0 contains order-bindin
3. **META-004** — 1) 14-remediation-progress.md'ye açık bir "Yayın kapısı durumu" bölümü ekle ve 13:444-449 ile 12:841 kriterlerini tek tek işaretle (geçti/geçmedi + gerekçe). 2) 12-path-to-first-class.md:852'yi düzelt: `cart_checkout_blocks` beyanını Horizon 0'dan çıkar ve "tarayıcı matrisi geçene kadar KASTEN beyan edilmiyor" notunu satırın içine yaz; 12:441'deki aynı ifadeyi de güncelle. 3) PR açıklamasındaki "r
4. **META-006** — CONFIGURATION.md:71'i düzelt ve sınırı açıkça yaz:

```markdown
4. **Yedek al.** Yönetici ekranındaki "Export filtered CSV" tek seferde en fazla
   **10.000 satır** verir (`CSV_MAX_ROWS`). Daha fazla kaydınız varsa tarih/durum
   filtrelerini daraltarak parça parça dışa aktarın ve toplam satır sayısının
   listedeki kayıt sayısıyla eşleştiğini doğrulayın, ya da doğrudan
   `{prefix}nicepay_transac
