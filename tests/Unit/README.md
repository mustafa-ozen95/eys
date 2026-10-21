# Birim testleri

Bu dizin `phpunit.xml` içinde tanımlı ama boştu; boş bir suite koşuda uyarı
üretiyordu.

Bu projede test ağırlığı **Feature** tarafında ve bu bilinçli: kasanın
davranışı (kimin neyi görebildiği, parolanın nereden çıktığı, döndürmenin hangi
sırayla yürüdüğü) HTTP ve veritabanıyla birlikte anlam kazanıyor. Saf hesap
yapan sınıflar — parola üreteci, CIDR karşılaştırması — kendi Feature
testlerinin içinden çağrılıyor.

Bir sınıf gerçekten çerçeveden bağımsızsa (Laravel'e hiç dokunmuyorsa) birim
testi buraya yazılır.
