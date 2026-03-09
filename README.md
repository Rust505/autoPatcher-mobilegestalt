# autoPatcher-mobilegestalt
iOS MobileGestalt Plist Patcher Python version of the MobileGestalt.plist patcher Supports offset-based patching for iOS 18.6+, 18.7+, 26.x in the CacheData section, as well as legacy pattern-based patching for iOS 15–18.5.

Python utility to patch com.apple.MobileGestalt.plist
Enables the shouldHacktivate state to bypass activation locks on supported iOS versions (15.0 – 26.x).
⚠️ Purpose: This tool modifies the plist to set the device into the shouldHacktivate state, effectively marking the device as activated without contacting Apple's activation
