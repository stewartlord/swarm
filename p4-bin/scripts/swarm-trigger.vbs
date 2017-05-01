' Perforce Swarm Trigger Script
'
' @copyright   2013-2016 Perforce Software. All rights reserved.
' @version     2016.1/1400259
'
' This script is meant to be called from a Perforce trigger.
' It should be placed on the Perforce Server machine.
' See usage information below for more details.
'
' NOTE:
' You must set your SWARM_HOST below (including http://)
' You must set your SWARM_TOKEN below
' * Log in to swarm as a super user and select 'About Swarm' to see your token value
' You must set your CURL_EXE location
' You must set the SWARM_LOG location
' You should place this script in a path that does not include spaces.
'
' Prerequisites:
' 1. curl.  curl can be downloaded from
' http://curl.haxx.se/download.html.
'
' TEST PLAN
'
' 1. Verify that script name prints out correctly
' 2. Dies without type and value arguments
' 3. Dies if unknown arguments
' 4. Dies if SWARM_HOST is still default
' 5. Dies is SWARM_TOKEN is still default
' 6. Calls out to curl in background and returns immediately
' 7. Logs error if curl fails
' 8. Successfully notifies Swarm if curl succeeds
' 9. Repeat 5&6&7 when run as trigger

SWARM_HOST = "http://my-swarm-host"
SWARM_TOKEN = "MY-UUID-STYLE-TOKEN"
SWARM_MAXTIME = 10
SWARM_LOG = "c:\temp\swarm.log"
CURL_EXE = "c:\windows\system32\curl.exe"

' DO NOT EDIT PAST THIS LINE ---------------------------------------


' Determine name and full path of the script
Myname = Wscript.ScriptName
Fullname = Wscript.ScriptFullName

' Parse arguments
Ttype = WScript.Arguments.Named.Item("type")
Tvalue = WScript.Arguments.Named.Item("value")

' Check arguments
If WScript.Arguments.Count <> 2 Then
    Wscript.Echo "Unexpected arguments"
    Usage Myname,Fullname
End If
If Ttype = "" Then
    Wscript.Echo "No event type supplied"
    Usage Myname,Fullname
End If
If Tvalue = "" Then
    Wscript.Echo "No value supplied"
    Usage Myname,Fullname
End If
If SWARM_HOST = "" Then
    Wscript.Echo "SWARM_HOST empty or default; please update in this script"
    Usage Myname,Fullname
End If
If SWARM_HOST = "http://my-swarm-host" Then
    Wscript.Echo "SWARM_HOST empty or default; please update in this script"
    Usage Myname,Fullname
End If
If SWARM_TOKEN = "" Then
    Wscript.Echo "SWARM_TOKEN empty or default; please update in this script"
    Usage Myname,Fullname
End If
If SWARM_TOKEN = "MY-UUID-STYLE-TOKEN" Then
    Wscript.Echo "SWARM_TOKEN empty or default; please update in this script"
    Usage Myname,Fullname
End If

SWARM_QUEUE = SWARM_HOST & "/queue/add/" & SWARM_TOKEN
Set WshShell = WScript.CreateObject("WScript.Shell")
DoubleQuote = chr(34)
WshShell.Run DoubleQuote & CURL_EXE & DoubleQuote & " --output " & DoubleQuote & SWARM_LOG & DoubleQuote & " --stderr " & DoubleQuote & SWARM_LOG & DoubleQuote & " --max-time " & SWARM_MAXTIME & " --data " & Ttype & "," & Tvalue & " " & SWARM_QUEUE, 0

Wscript.Quit 0

Sub Usage(aname,afullname)
    Wscript.Echo "Usage: cscript " & aname & " /type:<type> /value:<value>"
    Wscript.Echo "     /type: specify the event type (e.g. job, shelve, commit) "
    Wscript.Echo "     /value: specify the ID value "
    Wscript.Echo ".  "
    Wscript.Echo " This script is meant to be called from a Perforce trigger. "
    Wscript.Echo " It should be placed on the Perforce Server machine and the "
    Wscript.Echo " following entries should be added using 'p4 triggers': "
    Wscript.Echo ".  "
    Wscript.Echo "   swarm.job        form-commit   job    ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:job /value:%formname%"" "
    Wscript.Echo "   swarm.user       form-commit   user   ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:user /value:%formname%"" "
    Wscript.Echo "   swarm.userdel    form-delete   user   ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:userdel /value:%formname%"" "
    Wscript.Echo "   swarm.group      form-commit   group  ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:group /value:%formname%"" "
    Wscript.Echo "   swarm.groupdel   form-delete   group  ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:groupdel /value:%formname%"" "
    Wscript.Echo "   swarm.changesave form-save     change ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:changesave /value:%formname%"" "
    Wscript.Echo "   swarm.shelve     shelve-commit //...  ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:shelve /value:%change%"" "
    Wscript.Echo "   swarm.commit     change-commit //...  ""C:\windows\system32\cscript.EXE /nologo %quote%" & afullname & "%quote% /type:commit /value:%change%"" "
    Wscript.Echo ". "
    Wscript.Echo " Please note that the use of '%quote%' is not supported on 2010.2 servers (they are harmless"
    Wscript.Echo " though); if you're using this version, ensure you don't have any spaces in the pathname to"
    Wscript.Echo " this script."
    Wscript.Echo ". "
    Wscript.Echo " Be sure to modify the SWARM_HOST and SWARM_TOKEN variable in this script as appropriate. "
    Wscript.Echo " You can obtain a value for SWARM_TOKEN by logging into Swarm as a user with 'super' privileges"
    Wscript.Echo " and selecting About Swarm from the username drop-down in the top-right; there, the token will"
    Wscript.Echo " be displayed."
    Wscript.Quit 99
End Sub
