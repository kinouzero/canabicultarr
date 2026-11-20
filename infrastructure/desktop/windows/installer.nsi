Target amd64-unicode
!include "MUI2.nsh"

!ifndef DESKTOP_VERSION
!define DESKTOP_VERSION "0.2.0-preview.7"
!endif

Name "Canabicultarr Desktop"
OutFile "/out/Canabicultarr-Desktop-preview-win-x64.exe"
InstallDir "$LOCALAPPDATA\Programs\Canabicultarr"
RequestExecutionLevel user
Var DesktopLock
Var StagedInstall
Var PreviousInstall
SetCompressor /SOLID lzma

!define MUI_ICON "payload\Canabicultarr.ico"
!define MUI_UNICON "payload\Canabicultarr.ico"
!define MUI_ABORTWARNING
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_FUNCTION StartCanabicultarr

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"
!insertmacro MUI_LANGUAGE "French"

LangString Existing ${LANG_ENGLISH} "The installation folder exists and is not registered to Canabicultarr."
LangString Existing ${LANG_FRENCH} "Le dossier d’installation existe déjà et n’est pas associé à Canabicultarr."
LangString Running ${LANG_ENGLISH} "Stop Canabicultarr from its setup screen before continuing."
LangString Running ${LANG_FRENCH} "Arrêtez Canabicultarr depuis son assistant avant de continuer."
LangString InstallFailed ${LANG_ENGLISH} "The installer could not replace the program files. Check disk space and close Canabicultarr."
LangString InstallFailed ${LANG_FRENCH} "Le programme n’a pas pu être remplacé. Vérifiez l’espace disque et quittez Canabicultarr."


!macro LockDesktop
    ; Keep the handle until exit, including during the complete install or uninstall.
    System::Call 'kernel32::CreateMutexW(p 0, i 0, w "Local\Canabicultarr-Desktop") p .r0 ?e'
    Pop $1
    ${If} $0 == 0
    ${OrIf} $1 == 183 ; ERROR_ALREADY_EXISTS
        IfSilent +2
        MessageBox MB_ICONSTOP "$(Running)"
        Abort
    ${EndIf}
    StrCpy $DesktopLock $0
!macroend

Function .onInit
    StrCpy $INSTDIR "$LOCALAPPDATA\Programs\Canabicultarr"
    !insertmacro LockDesktop
    IfFileExists "$INSTDIR\*" 0 installed_path_checked
        ReadRegStr $0 HKCU "Software\Canabicultarr" "InstallDir"
        StrCmp $0 $INSTDIR installed_path_checked
        IfSilent +2
        MessageBox MB_ICONSTOP "$(Existing)"
        Abort
    installed_path_checked:
FunctionEnd

Function StartCanabicultarr
    System::Call 'kernel32::CloseHandle(p $DesktopLock)'
    ExecShell "open" "$INSTDIR\Canabicultarr.exe"
FunctionEnd

!macro RemoveProgram DIRECTORY
    Delete "${DIRECTORY}\Canabicultarr.exe"
    Delete "${DIRECTORY}\Canabicultarr.ico"
    Delete "${DIRECTORY}\Uninstall.exe"
    RMDir /r "${DIRECTORY}\project"
    RMDir /r "${DIRECTORY}\runtime"
    RMDir /r "${DIRECTORY}\branding"
    RMDir "${DIRECTORY}"
!macroend

Section "Canabicultarr"
    ClearErrors
    CreateDirectory "$LOCALAPPDATA\Programs"
    GetTempFileName $StagedInstall "$LOCALAPPDATA\Programs"
    IfErrors install_failed
    Delete "$StagedInstall"
    SetOutPath "$StagedInstall"
    IfErrors discard_stage
    File /r "payload\*"
    IfErrors discard_stage
    WriteUninstaller "$StagedInstall\Uninstall.exe"
    IfErrors discard_stage
    SetOutPath "$TEMP"
    StrCpy $PreviousInstall "$StagedInstall.previous"
    IfFileExists "$INSTDIR\*" 0 publish_install
        Rename "$INSTDIR" "$PreviousInstall"
        IfErrors discard_stage
    publish_install:
    Rename "$StagedInstall" "$INSTDIR"
    IfErrors restore_previous
    IfFileExists "$PreviousInstall\*" 0 register_install
        !insertmacro RemoveProgram "$PreviousInstall"
    register_install:
    CreateShortCut "$SMPROGRAMS\Canabicultarr.lnk" "$INSTDIR\Canabicultarr.exe" "" "$INSTDIR\Canabicultarr.ico"
    WriteRegStr HKCU "Software\Canabicultarr" "InstallDir" "$INSTDIR"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "DisplayName" "Canabicultarr Desktop"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "DisplayVersion" "${DESKTOP_VERSION}"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "DisplayIcon" "$INSTDIR\Canabicultarr.ico"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "UninstallString" '"$INSTDIR\Uninstall.exe"'
    WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "NoModify" 1
    WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr" "NoRepair" 1
    Goto install_done

    restore_previous:
    IfFileExists "$PreviousInstall\*" 0 discard_stage
        Rename "$PreviousInstall" "$INSTDIR"
    discard_stage:
    SetOutPath "$TEMP"
    !insertmacro RemoveProgram "$StagedInstall"
    install_failed:
    IfSilent +2
    MessageBox MB_ICONSTOP "$(InstallFailed)"
    SetErrorLevel 2
    Abort
    install_done:
SectionEnd

Function un.onInit
    !insertmacro LockDesktop
    ReadRegStr $0 HKCU "Software\Canabicultarr" "InstallDir"
    ${If} $INSTDIR != "$LOCALAPPDATA\Programs\Canabicultarr"
    ${OrIf} $INSTDIR != $0
        IfSilent +2
        MessageBox MB_ICONSTOP "$(Existing)"
        Abort
    ${EndIf}
FunctionEnd

Section Uninstall
    SetOutPath "$TEMP"
    Delete "$SMPROGRAMS\Canabicultarr.lnk"
    !insertmacro RemoveProgram "$INSTDIR"
    DeleteRegKey HKCU "Software\Canabicultarr"
    DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Canabicultarr"
SectionEnd
