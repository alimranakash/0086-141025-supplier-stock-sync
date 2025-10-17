# Troubleshooting: Backorder Messages Not Saving or Displaying

## Issue Description
Custom backorder messages saved in the "Messages" settings page are not being applied on the frontend. Product pages continue to show the default message instead of the custom saved message.

## Recent Improvements Made

### 1. Enhanced Form Submission Handling
- **Changed**: Replaced `wp_verify_nonce()` with `check_admin_referer()` for better security
- **Added**: Proper redirect after save to prevent form resubmission
- **Added**: Better input validation and sanitization
- **Result**: More reliable form processing

### 2. Added Debug Mode
- **Access**: Go to Messages page and add `&debug=1` to the URL
- **Shows**: 
  - Current saved messages in database
  - Messages returned by `get_message()` method
  - Raw database values
- **URL Example**: `wp-admin/admin.php?page=supplier-messages&debug=1`

### 3. Improved Success Notifications
- **Changed**: Success message now shows after redirect
- **Added**: Dismissible admin notice
- **Result**: Clear confirmation when settings are saved

## Diagnostic Steps

### Step 1: Check if Messages Are Being Saved

1. Go to: **WP Admin → Supplier Stock Sync → Messages**
2. Add `&debug=1` to the URL (e.g., `admin.php?page=supplier-messages&debug=1`)
3. Click "Show Debug Information" link at the bottom
4. Check the "Current saved messages in database" section
5. Verify if your custom messages appear there

**Expected Result**: You should see your custom messages in the debug output.

**If NOT showing**: Messages are not being saved to the database.

### Step 2: Test Form Submission

1. Go to: **WP Admin → Supplier Stock Sync → Messages**
2. Change one of the messages (e.g., Product Page Message)
3. Click "Save Messages"
4. Look for the green success notice at the top
5. Check if the URL changed to include `&settings-updated=true`
6. Enable debug mode (`&debug=1`) and verify the message was saved

**Expected Result**: Success notice appears and debug shows the new message.

**If NOT working**: There may be a form submission issue.

### Step 3: Use the Diagnostic Test File

1. Upload `test-message-settings.php` to your WordPress root directory
2. Access it via browser: `https://yourdomain.com/test-message-settings.php`
3. Run through all 6 tests:
   - Test 1: Database Option Check
   - Test 2: Plugin get_message() Method Test
   - Test 3: Save Test Message
   - Test 4: Caching Check
   - Test 5: Direct Database Query
   - Test 6: Manual Form Submission Test

**Expected Result**: All tests should pass and show your custom messages.

### Step 4: Check for Caching Issues

**Common Caching Plugins**:
- WP Super Cache
- W3 Total Cache
- WP Rocket
- LiteSpeed Cache
- WP Fastest Cache

**Solution**: Clear all caches after saving messages:
1. Clear WordPress object cache
2. Clear page cache
3. Clear browser cache
4. Try in incognito/private browsing mode

### Step 5: Verify Frontend Display

1. Find a product with backorder status
2. View the product page
3. Look for the availability text
4. It should show your custom message instead of "Available on backorder"

**If still showing default**: Check the product's stock status:
- Go to: **Products → Edit Product**
- Check: **Inventory → Stock status** should be "On backorder"
- Check: **Inventory → Allow backorders** should be enabled

## Common Issues and Solutions

### Issue 1: Messages Save But Don't Display

**Possible Causes**:
- Caching (page cache, object cache, browser cache)
- Product stock status is not "onbackorder"
- Theme or another plugin overriding the availability text

**Solutions**:
1. Clear all caches
2. Verify product stock status is "onbackorder"
3. Test with a default WordPress theme (e.g., Twenty Twenty-Three)
4. Temporarily disable other plugins to check for conflicts

### Issue 2: Success Message Shows But Messages Not Saved

**Possible Causes**:
- Database write permissions issue
- WordPress options table issue
- Serialization problem

**Solutions**:
1. Check database write permissions
2. Run the diagnostic test file (Test 5: Direct Database Query)
3. Try manually setting the option via phpMyAdmin or database tool

### Issue 3: Form Doesn't Submit

**Possible Causes**:
- JavaScript error preventing form submission
- Nonce verification failing
- Server timeout

**Solutions**:
1. Check browser console for JavaScript errors
2. Disable JavaScript-heavy plugins temporarily
3. Check server error logs
4. Increase PHP max_execution_time if needed

### Issue 4: Empty Messages Being Saved

**Possible Causes**:
- Form fields not being populated
- Sanitization removing content
- POST data not being received

**Solutions**:
1. Check if form fields have values before submitting
2. Use debug mode to see what's actually saved
3. Check server POST size limits

## Code Reference

### Where Messages Are Saved
**File**: `supplier-stock-sync.php`  
**Method**: `admin_messages_page()`  
**Lines**: 533-562

```php
if ( isset( $_POST['save_messages'] ) && check_admin_referer( 'sss_save_messages', 'sss_messages_nonce' ) ) {
    $messages = array(
        'product' => sanitize_text_field( $_POST['product_message'] ),
        'checkout' => sanitize_text_field( $_POST['checkout_message'] ),
        'email' => sanitize_text_field( $_POST['email_message'] )
    );
    
    update_option( 'sss_backorder_messages', $messages );
}
```

### Where Messages Are Retrieved
**File**: `supplier-stock-sync.php`  
**Method**: `get_message()`  
**Lines**: 396-406

```php
private function get_message( $type ) {
    $messages = get_option( 'sss_backorder_messages', array() );
    
    $defaults = array(
        'product' => 'Order today for dispatch in 4 days',
        'checkout' => 'Your order will be dispatched in 4 days',
        'email' => 'Your order will be dispatched in 4 days'
    );
    
    return isset( $messages[$type] ) && !empty( $messages[$type] ) ? $messages[$type] : $defaults[$type];
}
```

### Where Messages Are Applied
**File**: `supplier-stock-sync.php`  
**Method**: `replace_backorder_availability_text()`  
**Lines**: 254-271

```php
public function replace_backorder_availability_text( $availability_text, $product ) {
    if ( ! $product ) {
        return $availability_text;
    }
    
    if ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) {
        if ( strpos( $availability_text, 'Available on backorder' ) !== false || 
             strpos( $availability_text, 'available on backorder' ) !== false ) {
            return $this->get_message( 'product' );
        }
    }
    
    return $availability_text;
}
```

## Manual Database Check

If all else fails, you can manually check/set the option in the database:

### Check Current Value
```sql
SELECT * FROM wp_options WHERE option_name = 'sss_backorder_messages';
```

### Manually Set Value
```sql
UPDATE wp_options 
SET option_value = 'a:3:{s:7:"product";s:45:"Your custom product message here";s:8:"checkout";s:46:"Your custom checkout message here";s:5:"email";s:43:"Your custom email message here";}'
WHERE option_name = 'sss_backorder_messages';
```

**Note**: The value is serialized PHP array. Use the diagnostic test file to generate the correct serialized format.

## Getting Help

If you've tried all the above steps and the issue persists:

1. **Collect Information**:
   - WordPress version
   - WooCommerce version
   - Active theme name
   - List of active plugins
   - Results from diagnostic test file
   - Debug mode output
   - Browser console errors (if any)
   - Server error logs (if accessible)

2. **Check Server Logs**:
   - PHP error log
   - WordPress debug log (enable WP_DEBUG in wp-config.php)
   - Web server error log

3. **Test in Clean Environment**:
   - Create a staging site
   - Use default WordPress theme
   - Disable all plugins except WooCommerce and Supplier Stock Sync
   - Test if messages save and display correctly

## Quick Reference

### Important URLs
- **Messages Settings**: `wp-admin/admin.php?page=supplier-messages`
- **Messages Settings (Debug)**: `wp-admin/admin.php?page=supplier-messages&debug=1`
- **Plugin Dashboard**: `wp-admin/admin.php?page=supplier-stock-sync`
- **Stock Tester**: `wp-admin/admin.php?page=supplier-stock-tester`

### Database Option Name
- **Option Name**: `sss_backorder_messages`
- **Option Type**: Serialized array
- **Array Keys**: `product`, `checkout`, `email`

### Default Messages
- **Product**: "Order today for dispatch in 4 days"
- **Checkout**: "Your order will be dispatched in 4 days"
- **Email**: "Your order will be dispatched in 4 days"

