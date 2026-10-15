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

Name "BilgeceLabs Tahta"
BrandingText "BilgeceLabs"
OutFile "bilgece-tahta-kurulum.exe"
InstallDir "$PROGRAMFILES64\BilgeceLabs\Tahta"
RequestExecutionLevel admin
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
!define MUI_ABORTWARNING

Var DevType          ; board/classroom/office/desk/other
Var Room             ; konum
Var hRoom
Var hType

!insertmacro MUI_PAGE_WELCOME
Page custom TypePageShow TypePageLeave
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Turkish"

; --- Cihaz türü / konum sayfası ---
Function TypePageShow
  !insertmacro MUI_HEADER_TEXT "Cihaz bilgisi" "Bu cihazın türünü ve konumunu seçin."
  nsDialogs::Create 1018
  Pop $0

  ${NSD_CreateLabel} 0 0 100% 12u "Cihaz türü:"
  Pop $0
  ${NSD_CreateDropList} 0 14u 100% 90u ""
  Pop $hType
  ${NSD_CB_AddString} $hType "Akıllı tahta"
  ${NSD_CB_AddString} $hType "Sınıf bilgisayarı"
  ${NSD_CB_AddString} $hType "İdare"
  ${NSD_CB_AddString} $hType "Danışma"
  ${NSD_CB_AddString} $hType "Diğer"
  ${NSD_CB_SelectString} $hType "Akıllı tahta"

  ${NSD_CreateLabel} 0 44u 100% 12u "Konum / sınıf (ör. 7-B, Danışma):"
  Pop $0
  ${NSD_CreateText} 0 58u 100% 12u ""
  Pop $hRoom

  nsDialogs::Show
FunctionEnd

Function TypePageLeave
  ${NSD_GetText} $hType $0
  ${NSD_GetText} $hRoom $Room
  StrCmp $0 "Akıllı tahta" 0 +3
    StrCpy $DevType "board"
    Goto done
  StrCmp $0 "Sınıf bilgisayarı" 0 +3
    StrCpy $DevType "classroom"
    Goto done
  StrCmp $0 "İdare" 0 +3
    StrCpy $DevType "office"
    Goto done
  StrCmp $0 "Danışma" 0 +3
    StrCpy $DevType "desk"
    Goto done
  StrCpy $DevType "other"
  done:
FunctionEnd

; Kurulum dosyasının adından kayıt anahtarını çıkar (_<32 hex>)
Function .onInit
  StrCpy $DevType "board"
  ; Açılış: BilgeceLabs logosu yumuşak geçişle (fade in/out)
  InitPluginsDir
  File "/oname=$PLUGINSDIR\splash.bmp" "splash.bmp"
  advsplash::show 1200 600 500 -1 "$PLUGINSDIR\splash"
  Pop $0
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  ; Önceki kurulum çalışıyorsa durdur ve çalışan programı kenara al:
  ; çalışan exe kilitlidir, üzerine yazılamaz (ajan .eski dosyasını açılışta siler).
  nsExec::Exec 'sc.exe stop BilgeceTahta'
  Pop $0
  Sleep 3000
  Delete "$INSTDIR\bilgece-tahta.exe.eski"
  Rename "$INSTDIR\bilgece-tahta.exe" "$INSTDIR\bilgece-tahta.exe.eski"
  File "/oname=bilgece-tahta.exe" "${AGENT}"

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

  DetailPrint "Ajan kuruluyor…"
  ${If} $1 != ""
    nsExec::ExecToLog '"$INSTDIR\bilgece-tahta.exe" install --key $1 --type $DevType --room "$Room"'
    Pop $0
  ${Else}
    nsExec::ExecToLog '"$INSTDIR\bilgece-tahta.exe" install --type $DevType --room "$Room"'
    Pop $0
  ${EndIf}

  ; Programlar ve Özellikler kaydı
  WriteUninstaller "$INSTDIR\kaldir.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayName" "BilgeceLabs Tahta"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "Publisher" "BilgeceLabs"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayIcon" "$INSTDIR\bilgece-tahta.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "UninstallString" '"$INSTDIR\kaldir.exe"'
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoRepair" 1

  ; Kurulum bitti: "tamamlandı" ekranını 5 sn göster (fade in/out dahil)
  DetailPrint "Tahta panele bağlanıyor…"
  File "/oname=$PLUGINSDIR\splash-bitti.bmp" "splash-bitti.bmp"
  advsplash::show 5000 600 600 -1 "$PLUGINSDIR\splash-bitti"
  Pop $0
SectionEnd

Section "Uninstall"
  nsExec::ExecToLog '"$INSTDIR\bilgece-tahta.exe" uninstall'
  Pop $0
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta"
  Delete "$INSTDIR\kaldir.exe"
  Delete "$INSTDIR\bilgece-tahta.exe"
  RMDir "$INSTDIR"
SectionEnd
