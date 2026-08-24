# WordPress.org Yayın Hazırlığı ve Güvenli Dağıtım

Bu belge, eklentinin WordPress.org Plugin Directory'ye **henüz yayımlanmadığı** mevcut durumdan, onaylı bir SVN deposuna kontrollü ilk yayın ve sonraki sürümlere geçiş yolunu tanımlar. Workflow'un bulunması eklentinin WordPress.org tarafından kabul edildiği veya listelendiği anlamına gelmez.

## Mevcut durum

- Kaynak ve GitHub release altyapısı hazırdır.
- WordPress.org biçiminde `readme.txt` eklenmiştir.
- `.github/workflows/deploy-wordpress-org.yml` yalnızca elle çalışır ve varsayılanı `dry-run`dır.
- `dry-run`, yayın adayını üretip artifact olarak saklar; SVN bağlantısı veya yazma işlemi yapmaz.
- Gerçek yayın; onaylı slug, immutable Git etiketi, korumalı GitHub environment, kesin onay metni ve SVN secrets olmadan çalışmaz.
- WordPress.org slug'ı henüz onaylanmadığı için bu altyapı şu anda herhangi bir listeleme iddiasında bulunmaz.

## Yayından önce zorunlu kararlar ve dış işlemler

### 1. WordPress.org hesabı ve gönderim

1. Düzenli takip edilen e-posta adresiyle bir WordPress.org hesabı oluşturun.
2. `plugins@wordpress.org` adresini e-posta güvenli listenize alın.
3. Üretime hazır kurulum ZIP'ini WordPress.org'un eklenti ekleme ekranından incelemeye gönderin:
   https://wordpress.org/plugins/developers/add/
4. İnceleme ekibinden gelen sorulara aynı e-posta zinciri üzerinden cevap verin.
5. Onay e-postasındaki kesin slug ve SVN adresini kaydedin. Workflow'a tahmini bir slug değil, yalnızca bu onaylı değer girilmelidir.

Gönderilen ZIP eksiksiz, kurulabilir ve 10 MB'ın altında olmalıdır. Geliştirme araçları, testler, gereksiz loglar veya üretilmemiş kaynaklar içermemelidir.

### 2. İsim, marka ve slug kararı

WordPress.org slug'ı onaylandıktan sonra değiştirilemez. WordPress.org, hak sahibinin resmi eklentisi olmayan başvurularda slug'ın bir ticari markayla başlamasını reddedebilir. `nicepay-payment-gateway` bu nedenle onaylanmış kabul edilmemelidir.

İnceleme ekibi farklı bir slug verirse yayımdan önce aşağıdakiler birlikte değiştirilmelidir:

- `Text Domain` ve bütün gettext domain kullanımları,
- dil dosyalarının adları,
- release paketinin üst dizini,
- WordPress.org `readme.txt` bilgileri,
- workflow'a girilen `plugin_slug`,
- gerekiyorsa eklenti görünen adı ve marka feragati.

Workflow, girilen onaylı slug `Text Domain` ile aynı değilse durur.

### 3. Üçüncü taraf hizmet ve marka incelemesi

Başvuru öncesinde şu konular yazılı olarak netleştirilmelidir:

- NICEPAY markasının eklenti adı, açıklaması ve görsellerde kullanım yetkisi,
- NICEPAY'in hizmet koşulları ile eklentinin kullandığı legacy PG-Web v3 akışının uyumu,
- dış NICEPAY JavaScript'inin ödeme hizmetinin zorunlu parçası olduğu,
- müşteri verilerinin hangi durumda NICEPAY'e gönderildiği,
- geçerli hizmet koşulları ve gizlilik politikası bağlantıları.

`readme.txt`, NICEPAY'in üçüncü taraf hizmet olduğunu; ödeme sırasında iletilen veri kategorilerini; telemetri gönderilmediğini; hizmet koşulları ve gizlilik bağlantılarını açıklar. Bu metin her sürüm öncesinde güncellik açısından tekrar kontrol edilmelidir.

### 4. Dağıtım kanalı için `Update URI`

Eklenti şu anda WordPress.org dışı dağıtımda slug çakışmasına karşı GitHub `Update URI` başlığı taşır. WordPress.org üzerinden yayımlanacak sürümde bu üçüncü taraf başlığı kaldırılmalıdır. Sürüm tutarlılık kontrolü normal GitHub paketlerinde başlığı zorunlu tutar; WordPress.org aday modunda ise yalnız başlığın kaldırılmasına veya mevcut resmî GitHub değerine izin verir. Publish işi ayrıca başlığın tamamen kaldırılmış olmasını zorunlu kılar.

Korunan publish işi, ana PHP dosyasında `Update URI` bulunduğu sürece durur. Böylece GitHub güncelleme kanalını işaretleyen bir paket yanlışlıkla WordPress.org'a gönderilemez.

### 5. Lisans ve içerik envanteri

WordPress.org'a gönderilen bütün kod, veri ve görseller GPL uyumlu bir lisansa sahip olmalıdır. MIT lisansı GPL uyumludur; yine de başvuru öncesinde aşağıdaki envanter insan tarafından doğrulanmalıdır:

- bütün kaynak dosyaları ve kopyalanan kod parçaları,
- JavaScript/CSS bağımlılıkları,
- ikon, banner ve ekran görüntülerinin hakları,
- marka/logoların kullanım izni,
- dış hizmet/API koşulları.

Sıkıştırılmış veya derlenmiş kod dağıtılıyorsa okunabilir kaynağın nerede olduğu `readme.txt` içinde belirtilmelidir. Bu proje, kaynak deposunun bağlantısını açıkça verir.

## `readme.txt` sürüm kuralları

Her kararlı sürümde aşağıdaki değerler aynı olmalıdır:

- Git etiketi: `v2.0.0`
- Plugin header `Version`: `2.0.0`
- `NICEPAY_VERSION`: `2.0.0`
- `readme.txt` içindeki `Stable tag`: `2.0.0`
- `CHANGELOG.md` en üst sürümü: `2.0.0`
- çeviri POT proje sürümü: `2.0.0`

`Stable tag: trunk` kullanılmamalıdır. WordPress.org, önce trunk içindeki `Stable tag` değerini okur ve ardından o numaralı `/tags/<version>/` dizinini kararlı sürüm olarak kullanır.

`Tested up to` yalnızca gerçekten test edilmiş WordPress ana sürümünü göstermelidir. WordPress ve WooCommerce sürümleri yükseldikçe bu alan otomatik olarak ileri alınmamalı; test matrisi geçtikten sonra güncellenmelidir.

Başvuru ve her sürüm öncesi resmi validator kullanılmalıdır:
https://wordpress.org/plugins/developers/readme-validator/

## WordPress.org görselleri

WordPress.org'a özgü görseller eklenti ZIP'inin içine konmaz. SVN deposunun `trunk` veya `tags` dizinine değil, üst seviyedeki `/assets` dizinine gider. Bu projede hazır ve hakları doğrulanmış görsel olmadığı için sahte veya geçici binary artwork eklenmemiştir.

Hazır olduklarında dosyalar Git deposunda `.wordpress-org/` altında tutulur. Desteklenen temel adlar:

- `banner-772x250.png`
- `banner-1544x500.png` (retina; normal banner'ın yerine tek başına kullanılamaz)
- `icon-128x128.png`
- `icon-256x256.png`
- isteğe bağlı `icon.svg` ile birlikte PNG fallback
- `screenshot-1.png`, `screenshot-2.png`, ...

Her `screenshot-N` dosyası için `readme.txt` içinde aynı numaralı bir ekran görüntüsü açıklaması olmalıdır. Bütün dosya adları küçük harfli olmalı; görsel lisansı ve logo/marka yetkisi yayımdan önce kayıt altına alınmalıdır.

## GitHub repository ayarları

### Korumalı environment

Repository Settings > Environments altında tam adı aşağıdaki gibi olan environment oluşturun:

`wordpress-org-production`

Bu environment için:

- en az bir zorunlu reviewer,
- mümkünse yalnızca korumalı release tag'lerinden deployment,
- environment admin bypass'ın kapatılması,
- secrets erişiminin yalnız bu environment ile sınırlandırılması

önerilir. Reviewer onayı yapılandırılmazsa YAML içindeki `environment` tek başına insan onayı garantilemez.

İnceleme onayından sonra, aynı environment içine bir configuration variable ekleyin:

- `WPORG_PLUGIN_SLUG`: WordPress.org Plugin Review Team'in onay e-postasında verdiği kesin slug.

Publish input'u bu korumalı değerle bire bir eşleşmeden workflow ilerlemez. Değer tahmini olarak veya inceleme tamamlanmadan eklenmemelidir.

### Environment secrets

WordPress.org hesap ayarlarından SVN'e özel parola üretin ve şu iki secret'ı yalnız `wordpress-org-production` environment'ına ekleyin:

- `WPORG_SVN_USERNAME`: Büyük/küçük harfe duyarlı WordPress.org kullanıcı adı; SVN commit yetkisi olmalıdır.
- `WPORG_SVN_PASSWORD`: Normal hesap parolası yerine SVN'e özel parola.

Secrets repository seviyesinde tutulmamalı ve Actions debug logging açılmamalıdır. Workflow bu değerleri loglamaz ve SVN istemcisinde `--no-auth-cache` kullanır.

## Workflow'un güvenlik modeli

Workflow yalnız `workflow_dispatch` ile elle başlatılır. Dört input alır:

- `version`: `v` olmadan kararlı `x.y.z` sürümü,
- `plugin_slug`: inceleme ekibinin onayladığı kesin slug,
- `mode`: varsayılan `dry-run` veya `publish`,
- `confirmation`: publish için bire bir `PUBLISH:<slug>:<version>`.

### Dry run

1. Yalnız `refs/tags/v<version>` immutable Git etiketi checkout edilir.
2. Etiketin `development` geçmişinde olduğu doğrulanır.
3. Sürüm, minimum gereksinim, lisans, text domain ve `Stable tag` tutarlılığı kontrol edilir.
4. Mevcut release script'i ile ZIP oluşturulur ve smoke test uygulanır.
5. `readme.txt` aynı doğrulanmış pakete eklenir.
6. WordPress.org adayı ve SHA-256 dosyası yedi gün süreli GitHub artifact'ı olur.
7. SVN'e bağlanılmaz; yayın yapılamaz.

### Publish

Dry run adımlarına ek olarak:

1. `wordpress-org-production` environment kuralları devreye girer.
2. Kesin confirmation metni doğrulanır.
3. Girilen slug'ın environment içindeki onaylı `WPORG_PLUGIN_SLUG` ile aynı olduğu doğrulanır.
4. İki environment secret'ın da mevcut olduğu kontrol edilir.
5. Üçüncü taraf `Update URI` başlığı varsa işlem durur.
6. Prepare işinin ürettiği aynı ZIP ve SHA-256 indirilip doğrulanır.
7. Onaylı slug'a ait SVN deposunun mevcut olduğu salt-okunur `svn info` ile kontrol edilir.
8. Doğrulanmış paket `trunk` ve `/tags/<version>` alanlarına gönderilir.
9. WordPress.org SVN'den oluşan trunk ZIP'i ayrıca 30 günlük artifact olarak saklanır.

Workflow; 10up'ın aktif desteklenen WordPress.org deploy action'ının `2.3.0` sürümünü hareketli etiket yerine tam commit SHA ile sabitler. Checkout ve artifact action'ları da tam commit SHA ile sabittir.

## İlk yayın kontrol listesi

- [ ] NICEPAY legacy protokol fixture ve sandbox sertifikasyon açıkları kapatıldı.
- [ ] İsim/marka kullanımı ve onaylı WordPress.org slug yazılı olarak doğrulandı.
- [ ] WordPress.org incelemesi tamamlandı ve SVN deposu oluşturuldu.
- [ ] Onaylı WordPress.org contributor kullanıcı adları `readme.txt` dosyasına eklendi; tahmini GitHub kullanıcı adı kullanılmadı.
- [ ] Eklenti PHP header'ındaki üçüncü taraf `Update URI` kaldırıldı.
- [ ] `readme.txt` resmi validator'dan hatasız geçti.
- [ ] Plugin Check sonuçları incelendi; uyarılar açıklanmış veya düzeltilmiş durumda.
- [ ] Üçüncü taraf hizmet, şartlar ve gizlilik bağlantıları tekrar doğrulandı.
- [ ] Lisans ve marka hakları dahil bütün dağıtım içeriği gözden geçirildi.
- [ ] Gerçek tarayıcı, mobil, erişilebilirlik ve kullanılan checkout matrisi tamamlandı.
- [ ] WordPress `Tested up to` ve WooCommerce uyumluluk beyanları test kanıtıyla eşleşiyor.
- [ ] `wordpress-org-production` zorunlu reviewer ve tag korumasıyla oluşturuldu.
- [ ] Onay e-postasındaki kesin slug `WPORG_PLUGIN_SLUG` environment variable olarak eklendi.
- [ ] SVN'e özel parola environment secrets olarak eklendi.
- [ ] Git etiketi oluşturulmadan önce release ZIP'i ve SHA-256 manuel olarak doğrulandı.
- [ ] Workflow önce `dry-run` modunda çalıştırıldı ve artifact kurulum testi yapıldı.
- [ ] Publish girişi için ikinci bir kişi `PUBLISH:<slug>:<version>` değerini doğruladı.

## Sonraki sürümler

WordPress.org SVN bir geliştirme deposu değil, yayın deposudur. Küçük geliştirme commit'leri SVN'e gönderilmemelidir. Her kararlı sürüm için yeni ve artan bir version/tag kullanılmalı; aynı `/tags/<version>` dizini değiştirilmemelidir.

Sadece `readme.txt` veya WordPress.org asset güncellemesi gerektiğinde bu production workflow'u yeni sürüm gibi kullanmayın. Ayrı bir readme/assets workflow'u ancak slug onayı, environment koruması ve benzer açık onay kapılarıyla ayrıca tasarlanmalıdır.

## Resmi ve kullanılan kaynaklar

- WordPress.org gönderim ve bakım süreci: https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/
- WordPress.org SVN düzeni: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- WordPress.org `readme.txt` standardı: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress.org asset kuralları: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Plugin Directory kuralları: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Başvuru ve slug/marka SSS: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- Plugin header alanları ve `Update URI`: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
- 10up WordPress.org deploy action: https://github.com/10up/action-wordpress-plugin-deploy
