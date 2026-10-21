# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and
read that rule file.

| Applies to | Rule file |
| --- | --- |
| ** (her dosya, her komut) | .ai/rules/work.md |

Bu dizin proje YAZILDIKÇA büyür. Bir hata iki kez yapıldıysa ya da bir karar
kodun kendisinden okunamıyorsa, buraya bir kural satırı düşer — Boost'un
`record-rule` aracıyla; elle dosya açarak değil.

Kural yazmanın ölçüsü: "bunu bilmeyen biri aynı hatayı yapar mı?" Cevap hayırsa
kural değil, yorumdur ve kodun içine yazılır.
- `kvm-devices.md` — KVM over IP cihazlarının protokol notları; yeni model eklerken önce buraya bak.
- `remote-command.md` — SSH üzerinden parola isteyen komut çalıştırırken: yazmadan önce istemi gör.
