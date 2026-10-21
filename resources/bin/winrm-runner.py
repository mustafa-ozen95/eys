#!/usr/bin/env python3
"""
EYS — WinRM taşıyıcısı.

Neden ayrı bir betik: WinRM sunucusu `AllowUnencrypted=false` olduğunda
kimlik doğrulandıktan sonra mesajın da şifrelenmesini ister (NTLM oturum
anahtarıyla mühürleme). Bunu PHP'de elle yazmak, bir parola kasasına el
yapımı kripto koymak olurdu; yıllardır kullanılan pywinrm bu işi yapıyor.

Sözleşme — hepsi standart girdi/çıktıdan, hiçbir sır komut satırında değil:

  girdi : {"action":"verify"|"run", "host":..., "port":5985,
           "username":..., "password":..., "program":..., "arguments":[...],
           "env":{"AD":"deger"}, "timeout":60}

SIRLAR `env` İLE GİDER, standart girdiyle değil: kurulu pywinrm 0.3.0'da
`send_command_input` YOK ve çağrıldığında "AttributeError" ile düşüyor.
WS-Man kabuğu açılırken ortam değişkeni verilebiliyor;
değişken hedef makinenin komut satırında görünmez.
  çıktı : {"ok":true, "status":0, "stdout":"<base64>", "stderr":"<base64>"}
          {"ok":false, "error":"..."}
"""

import base64
import json
import sys

try:
    from winrm.protocol import Protocol
except Exception as e:  # pragma: no cover - kurulum eksikse anlaşılır mesaj
    print(json.dumps({"ok": False, "error": f"pywinrm yüklü değil: {e}"}))
    sys.exit(0)


def protocol(istek):
    zaman = int(istek.get("timeout", 60))

    return Protocol(
        endpoint="http://{}:{}/wsman".format(istek["host"], istek.get("port") or 5985),
        transport="ntlm",
        username=istek["username"],
        password=istek["password"],
        message_encryption="always",
        server_cert_validation="ignore",
        operation_timeout_sec=zaman,
        read_timeout_sec=zaman + 10,
    )


def main():
    istek = json.load(sys.stdin)
    p = protocol(istek)

    # Kimlik sınaması: kabuk açılıp hemen kapatılır, makinede iz kalmaz.
    #
    # Ortam değişkenleri KABUĞA verilir; komutun argümanlarına değil. Sır
    # taşıyan tek yol budur (bkz. yukarıdaki not).
    ortam = istek.get("env") or None
    kabuk = p.open_shell(env_vars=ortam)

    try:
        if istek.get("action") == "verify":
            return {"ok": True, "status": 0, "stdout": "", "stderr": ""}

        komut = p.run_command(kabuk, istek["program"], istek.get("arguments", []))

        try:
            girdi = istek.get("stdin") or ""

            if girdi:
                # Eski çağrı yolu. Kurulu sürümde desteklenmiyorsa sessizce
                # AttributeError vermek yerine ne olduğunu söyler.
                gonder = getattr(p, "send_command_input", None)

                if gonder is None:
                    return {
                        "ok": False,
                        "error": "Bu pywinrm sürümü standart girdiyi desteklemiyor; "
                                 "sır 'env' ile gönderilmeli.",
                    }

                gonder(kabuk, komut, girdi, end=True)

            cikti, hata, kod = p.get_command_output(kabuk, komut)
        finally:
            p.cleanup_command(kabuk, komut)

        return {
            "ok": True,
            "status": int(kod),
            "stdout": base64.b64encode(cikti).decode(),
            "stderr": base64.b64encode(hata).decode(),
        }
    finally:
        p.close_shell(kabuk)


if __name__ == "__main__":
    try:
        print(json.dumps(main()))
    except Exception as hata:  # noqa: BLE001 - her hata çağırana JSON olarak döner
        print(json.dumps({"ok": False, "error": f"{type(hata).__name__}: {hata}"}))
