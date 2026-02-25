# Firebase Storage Connectivity Issue - Fix Guide

## 🔍 Error Message
```
Failed to access Firebase Storage bucket 'abic-admin-accounting.firebasestorage.app'. 
Please verify the bucket exists in Firebase Console. 
Error: cURL error 6: Could not resolve host: storage.googleapis.com
```

## 🎯 Root Cause
The error `cURL error 6: Could not resolve host: storage.googleapis.com` indicates a **DNS resolution failure**. This means your server cannot resolve the domain name `storage.googleapis.com` to an IP address.

## ✅ Solutions

### Solution 1: Check DNS Configuration (Most Common)
1. **Test DNS resolution** from your server:
   ```bash
   # Windows (PowerShell)
   nslookup storage.googleapis.com
   
   # Linux/Mac
   dig storage.googleapis.com
   ```

2. **If DNS fails**, check your DNS settings:
   - Windows: Check network adapter DNS settings
   - Laragon: May need to configure DNS in network settings
   - Try using public DNS servers:
     - Google DNS: `8.8.8.8` and `8.8.4.4`
     - Cloudflare DNS: `1.1.1.1` and `1.0.0.1`

### Solution 2: Check Firewall/Network Restrictions
1. **Firewall**: Ensure outbound HTTPS (port 443) is allowed
2. **Corporate Network**: May block Google Cloud Storage
3. **VPN/Proxy**: May interfere with DNS resolution

### Solution 3: Check PHP cURL Configuration
1. **Verify cURL is working**:
   ```php
   // Test in PHP
   $ch = curl_init('https://storage.googleapis.com');
   curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
   curl_setopt($ch, CURLOPT_TIMEOUT, 10);
   $result = curl_exec($ch);
   $error = curl_error($ch);
   curl_close($ch);
   echo $error ? "Error: $error" : "Success";
   ```

2. **Check php.ini**:
   ```ini
   ; Ensure these are enabled
   extension=curl
   allow_url_fopen = On
   ```

### Solution 4: Use IP Address Directly (Temporary Workaround)
If DNS continues to fail, you can temporarily use IP addresses, but this is **not recommended** for production.

### Solution 5: Check Laravel Environment
1. **Verify `.env` file**:
   ```env
   FIREBASE_STORAGE_BUCKET=abic-admin-accounting.firebasestorage.app
   FIREBASE_CREDENTIALS_PATH=app/firebase-credentials.json
   ```

2. **Check credentials file exists**:
   ```bash
   # Should exist at:
   storage/app/firebase-credentials.json
   ```

### Solution 6: Network Connectivity Test
Test if you can reach Google Cloud Storage:
```bash
# Test HTTPS connection
curl -I https://storage.googleapis.com

# Test specific bucket (if accessible)
curl -I https://storage.googleapis.com/abic-admin-accounting.firebasestorage.app
```

## 🔧 Quick Fixes to Try

### Fix 1: Restart Network Services
```bash
# Windows
ipconfig /flushdns
netsh winsock reset

# Then restart your computer or network adapter
```

### Fix 2: Use Alternative DNS
1. Open Network Settings
2. Change DNS to:
   - Primary: `8.8.8.8`
   - Secondary: `8.8.4.4`
3. Restart network adapter

### Fix 3: Check Hosts File
Ensure `storage.googleapis.com` is not blocked in:
- Windows: `C:\Windows\System32\drivers\etc\hosts`
- Should NOT have entries blocking Google domains

### Fix 4: Laragon-Specific Fix
If using Laragon:
1. Check Laragon's network settings
2. Ensure Laragon's DNS is not interfering
3. Try running Laragon as Administrator
4. Check if Laragon's firewall rules are blocking outbound connections

## 📋 Diagnostic Steps

1. **Test DNS Resolution**:
   ```bash
   nslookup storage.googleapis.com
   ```
   Should return IP addresses (e.g., 142.250.191.16)

2. **Test HTTPS Connection**:
   ```bash
   curl -v https://storage.googleapis.com
   ```
   Should connect successfully

3. **Test from PHP**:
   ```php
   $ch = curl_init('https://storage.googleapis.com');
   curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
   curl_setopt($ch, CURLOPT_TIMEOUT, 10);
   curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
   $result = curl_exec($ch);
   $error = curl_error($ch);
   $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);
   
   echo "HTTP Code: $httpCode\n";
   echo $error ? "Error: $error\n" : "Success\n";
   ```

4. **Check Laravel Logs**:
   ```bash
   tail -f storage/logs/laravel.log
   ```
   Look for more detailed error messages

## 🚀 Prevention

1. **Use Reliable DNS**: Configure your system to use reliable DNS servers
2. **Monitor Network**: Set up monitoring for DNS resolution failures
3. **Add Retry Logic**: Implement retry logic in FirebaseStorageService for transient failures
4. **Use Connection Pooling**: Reuse connections where possible

## 📝 Notes

- This is typically a **network/DNS issue**, not a code issue
- The Firebase SDK itself is working correctly
- The problem is at the network layer (DNS resolution)
- Once DNS is fixed, Firebase Storage should work immediately

## 🔗 Related Files

- `app/Services/FirebaseStorageService.php` - Firebase Storage service
- `.env` - Environment configuration
- `storage/app/firebase-credentials.json` - Firebase credentials

## 💡 Alternative: Use Local Storage (Temporary)

If Firebase continues to have connectivity issues, you can temporarily use local storage:

1. Modify `TransactionController.php` to use local storage
2. Store files in `storage/app/public/transactions/`
3. Use `php artisan storage:link` to create symlink
4. Switch back to Firebase once connectivity is resolved
