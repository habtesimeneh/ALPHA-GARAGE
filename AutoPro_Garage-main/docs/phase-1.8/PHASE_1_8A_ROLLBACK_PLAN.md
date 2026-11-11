# Phase 1.8A — Apache VirtualHost Rollback & Recovery Procedures

**Phase:** 1.8A — MAMP Apache VirtualHost Security — Read-Only Inspection  
**Date:** 2026-11-11  
**Target:** Reverting Apache Configuration to Pre-Phase-1.8 State  
**Scope:** `C:\MAMP\conf\apache\httpd.conf` and `C:\MAMP\conf\apache\gms-ssl.conf`

---

## 1. Rollback Overview

If any syntax error, port conflict, or asset loading failure occurs during the planned Phase 1.8B cutover, this procedure restores Apache immediately to its known-good pre-Phase-1.8 state.

---

## 2. Pre-Change Backup Locations

Prior to applying any changes in Phase 1.8B, configuration backups will be created outside the active configuration paths:

| Original File | Recommended Backup Path |
|:---|:---|
| `C:\MAMP\conf\apache\httpd.conf` | `C:\MAMP\conf\apache\httpd.conf.pre-1.8.bak` |
| `C:\MAMP\conf\apache\gms-ssl.conf` | `C:\MAMP\conf\apache\gms-ssl.conf.pre-1.8.bak` |

---

## 3. Step-by-Step Rollback Instructions

### Step 3.1: Stop Apache in MAMP
1. Open the MAMP control panel.
2. Click **Stop Servers** (or stop the Apache service).

### Step 3.2: Restore Configuration Files via PowerShell
Open PowerShell as Administrator and run:

```powershell
# 1. Restore httpd.conf
Copy-Item "C:\MAMP\conf\apache\httpd.conf.pre-1.8.bak" "C:\MAMP\conf\apache\httpd.conf" -Force

# 2. Restore gms-ssl.conf
Copy-Item "C:\MAMP\conf\apache\gms-ssl.conf.pre-1.8.bak" "C:\MAMP\conf\apache\gms-ssl.conf" -Force

# 3. If alphagarage-vhost.conf was created, remove it
if (Test-Path "C:\MAMP\conf\apache\alphagarage-vhost.conf") {
    Remove-Item "C:\MAMP\conf\apache\alphagarage-vhost.conf" -Force
}
```

### Step 3.3: Validate Restored Configuration
Run Apache's syntax checker to verify the restored files:

```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -f "C:\MAMP\conf\apache\httpd.conf" -t
```
*Expected Output:* `Syntax OK`

Run the VirtualHost dump to confirm pre-Phase-1.8 state:
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -f "C:\MAMP\conf\apache\httpd.conf" -S
```
*Expected Output:*
```
VirtualHost configuration:
*:443                  localhost (C:/MAMP/conf/apache/gms-ssl.conf:6)
ServerRoot: "C:/MAMP/bin/apache"
Main DocumentRoot: "C:/MAMP/htdocs"
```

### Step 3.4: Restart Apache
In MAMP, click **Start Servers**.

---

## 4. Post-Rollback Validation

Verify that pre-Phase-1.8 behavior is restored:

1. **Default Welcome Page:**
   Open `http://localhost/` in the browser; confirm MAMP's stock welcome page is displayed.
2. **Subdirectory Project Access:**
   Open `https://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php`; confirm the Alpha Garage sign-in page displays properly.
3. **Owner Login:**
   Sign in with `barok_admin` to verify operational database connectivity.
