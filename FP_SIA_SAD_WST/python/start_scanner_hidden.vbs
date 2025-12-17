' Library Hub QR Scanner - Hidden Launcher
' This script runs the scanner completely hidden (no visible window)
' The scanner will run in the background silently

Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")
strPath = FSO.GetParentFolderName(WScript.ScriptFullName)

' Use the silent batch file (no pauses, minimal output)
silentBat = strPath & "\start_scanner_silent.bat"

' Fallback to regular batch if silent doesn't exist
If Not FSO.FileExists(silentBat) Then
    silentBat = strPath & "\start_scanner.bat"
End If

' Run the batch file hidden (0 = hidden, False = don't wait for completion)
WshShell.Run """" & silentBat & """", 0, False
