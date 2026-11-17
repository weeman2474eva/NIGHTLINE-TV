package tv.nightline;

import android.app.Activity;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.TextView;

public final class MainActivity extends Activity {
    private WebView screen;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (BuildConfig.PANEL_URL.isEmpty()) {
            TextView message = new TextView(this);
            message.setText("NIGHTLINE TV requires a panel URL at build time.");
            message.setGravity(android.view.Gravity.CENTER);
            setContentView(message);
            return;
        }

        Uri panel = Uri.parse(BuildConfig.PANEL_URL);
        screen = new WebView(this);
        screen.getSettings().setJavaScriptEnabled(true);
        screen.getSettings().setDomStorageEnabled(true);
        screen.getSettings().setAllowFileAccess(false);
        screen.getSettings().setAllowContentAccess(false);
        screen.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri url = request.getUrl();
                return !"https".equals(url.getScheme()) || !panel.getAuthority().equals(url.getAuthority());
            }
        });
        setContentView(screen);
        screen.requestFocus();
        screen.loadUrl(BuildConfig.PANEL_URL + "/tv");
    }

    @Override
    public void onBackPressed() {
        if (screen != null && screen.canGoBack()) {
            screen.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onPause() {
        if (screen != null) screen.onPause();
        super.onPause();
    }
}
