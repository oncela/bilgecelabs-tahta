; BilgeceLabs Tahta kurulum sihirbazı (NSIS)
; Derleme: makensis -DAGENT=<yol> -DVERSION=<sürüm> bilgece-tahta.nsi
; Kayıt anahtarı kurulum dosyasının adından okunur: BilgeceTahtaKurulum_<anahtar>.exe

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "nsDialogs.nsh"
!include "FileFunc.nsh"

!ifndef VERSION
  !define VERSION "0.0.0"
!endif
!ifndef AGENT
  !error "AGENT tanımlı değil (ajan exe yolu)"
!endif
!ifndef DESTEK
  !error "DESTEK tanımlı değil (bilgece-destek.exe yolu)"
!endif

Name "BilgeceLabs Tahta"
BrandingText "BilgeceLabs"
OutFile "bilgece-tahta-kurulum.exe"
InstallDir "$PROGRAMFILES64\BilgeceLabs\Tahta"
RequestExecutionLevel admin
; Kurulum penceresi yok: ekranda yalnız logo + yükleme çubuğu (ajanın kurulum-ekrani modu)
SilentInstall silent
ShowInstDetails nevershow
VIProductVersion "${VERSION}.0"
VIAddVersionKey "ProductName" "BilgeceLabs Tahta"
VIAddVersionKey "CompanyName" "BilgeceLabs"
VIAddVersionKey "FileDescription" "BilgeceLabs Tahta Kurulum"
VIAddVersionKey "LegalCopyright" "© 2026 BilgeceLabs"
VIAddVersionKey "FileVersion" "${VERSION}.0"
VIAddVersionKey "ProductVersion" "${VERSION}.0"

!define MUI_ICON "bilgece.ico"
!define MUI_UNICON "bilgece.ico"
; Cihaz türü ve konum sorulmaz (panelden atanır). Toplu/sessiz kurulum için
; isteğe bağlı komut satırı: kurulum.exe /S /TUR=classroom /KONUM=7-B
Var DevType
Var Room
Var Quiet          ; 1: hiçbir şey gösterme (/S)
Var Durum          ; kurulum ekranına "bitti" sinyali

!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Turkish"

Function .onInit
  ${GetParameters} $R0
  ${GetOptions} $R0 "/TUR=" $DevType
  ${GetOptions} $R0 "/KONUM=" $Room
  StrCpy $Quiet 0
  ClearErrors
  ${GetOptions} $R0 "/S" $R1
  ${IfNot} ${Errors}
    StrCpy $Quiet 1
  ${EndIf}
FunctionEnd

Section "Install"
  StrCpy $Durum "$TEMP\bilgece-kurulum.durum"
  Delete $Durum
  ${If} $Quiet == 0
    ; Kurulum ekranı (logo + yükleme çubuğu): küçük destek programının bir modu
    InitPluginsDir
    File "/oname=$PLUGINSDIR\BilgeceKurulumEkrani.exe" "${DESTEK}"   ; farklı ad: taskkill onu kapatmasın
    Exec '"$PLUGINSDIR\BilgeceKurulumEkrani.exe" kurulum-ekrani "$Durum"'
  ${EndIf}

  SetOutPath "$INSTDIR"
  ; Önceki kurulum çalışıyorsa durdur ve çalışan programı kenara al:
  ; çalışan exe kilitlidir, üzerine yazılamaz (ajan .eski dosyasını açılışta siler).
  nsExec::Exec 'sc.exe stop BilgeceTahta'
  Pop $0
  Sleep 3000
  Delete "$INSTDIR\bilgece-tahta.exe.eski"
  Rename "$INSTDIR\bilgece-tahta.exe" "$INSTDIR\bilgece-tahta.exe.eski"
  File "/oname=bilgece-tahta.exe" "${AGENT}"
  ; Self servis penceresi (saatin yanındaki simge): açıksa kapat, yenisini koy
  nsExec::Exec 'taskkill.exe /F /IM bilgece-destek.exe'
  Pop $0
  Delete "$INSTDIR\bilgece-destek.exe.eski"
  Rename "$INSTDIR\bilgece-destek.exe" "$INSTDIR\bilgece-destek.exe.eski"
  File "/oname=bilgece-destek.exe" "${DESTEK}"
  Delete "$INSTDIR\bilgece-destek.exe.eski"

  ; Kayıt anahtarı: kurulum exe adından (BilgeceTahtaKurulum_<anahtar>.exe)
  ${GetFileName} "$EXEPATH" $0        ; dosya adı
  StrCpy $1 ""
  StrLen $2 $0
  ; "_" sonrası 32 karakteri al
  StrCpy $3 0
  loop:
    IntCmp $3 $2 done2 0 done2
    StrCpy $4 $0 1 $3
    StrCmp $4 "_" 0 +3
      IntOp $5 $3 + 1
      StrCpy $1 $0 32 $5
    IntOp $3 $3 + 1
    Goto loop
  done2:

  StrCpy $6 ""
  ${If} $1 != ""
    StrCpy $6 "$6 --key $1"
  ${EndIf}
  ${If} $DevType != ""
    StrCpy $6 "$6 --type $DevType"
  ${EndIf}
  ${If} $Room != ""
    StrCpy $6 '$6 --room "$Room"'
  ${EndIf}
  nsExec::Exec '"$INSTDIR\bilgece-tahta.exe" install$6'
  Pop $7

  ; Programlar ve Özellikler kaydı
  WriteUninstaller "$INSTDIR\kaldir.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayName" "BilgeceLabs Tahta"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "Publisher" "BilgeceLabs"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayIcon" "$INSTDIR\bilgece-tahta.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "UninstallString" '"$INSTDIR\kaldir.exe"'
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoRepair" 1

  ; Kurulum ekranına sonucu bildir: "tamam" -> 4 sn "Kurulum tamamlandı", sonra solar.
  ; Önce geçici dosyaya yazılıp yeniden adlandırılır (ekran yarım dosya okumasın).
  FileOpen $8 "$Durum.tmp" w
  ${If} $7 == 0
    FileWrite $8 "tamam"
  ${Else}
    FileWrite $8 "hata"
  ${EndIf}
  FileClose $8
  Rename "$Durum.tmp" $Durum
  ${If} $7 != 0
    ${If} $Quiet == 0
      ; SilentInstall'da /SD'siz mesaj kutusu gösterilir
      MessageBox MB_ICONSTOP "BilgeceLabs Tahta kurulumu tamamlanamadı.$\r$\nKurulum dosyasını panelden yeniden indirip tekrar deneyin."
    ${EndIf}
    SetErrorLevel 1
  ${EndIf}
SectionEnd

Section "Uninstall"
  nsExec::ExecToLog '"$INSTDIR\bilgece-tahta.exe" uninstall'
  Pop $0
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta"
  Delete "$INSTDIR\kaldir.exe"
  nsExec::Exec 'taskkill.exe /F /IM bilgece-destek.exe'
  Pop $0
  Delete "$INSTDIR\bilgece-tahta.exe"
  Delete "$INSTDIR\bilgece-destek.exe"
  RMDir "$INSTDIR"
SectionEnd
