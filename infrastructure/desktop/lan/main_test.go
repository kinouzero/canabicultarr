package main

import (
	"bytes"
	"crypto/tls"
	"crypto/x509"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestGatewayBoundaries(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get(proxyHeader) != "fixture-proof" || r.Header.Get(clientHeader) != "192.168.44.6" || r.Header.Get("X-Forwarded-For") != "" || r.Header.Get("Forwarded") != "" {
			t.Error("gateway accepted untrusted forwarding metadata")
		}
		w.Header().Set("X-Canabicultarr-Desktop-Run", "fixture-run")
		w.WriteHeader(http.StatusNoContent)
	}))
	defer upstream.Close()
	target, _ := url.Parse(upstream.URL)
	gateway := newGateway(target, "cana-desktop.test:8443", "fixture-proof", netip.MustParsePrefix("192.168.44.0/24"))

	for _, check := range []struct {
		name, method, path, peer, host string
		status                         int
	}{
		{"same subnet", "GET", "/up", "192.168.44.6:9000", "cana-desktop.test:8443", http.StatusNoContent},
		{"different private subnet", "GET", "/up", "192.168.45.6:9000", "cana-desktop.test:8443", http.StatusForbidden},
		{"wrong hostname", "GET", "/up", "192.168.44.6:9000", "wrong.test", http.StatusBadRequest},
		{"account setup", "POST", "/api/v1/setup/", "192.168.44.6:9000", "cana-desktop.test:8443", http.StatusNotFound},
	} {
		t.Run(check.name, func(t *testing.T) {
			request := httptest.NewRequest(check.method, check.path, nil)
			request.RemoteAddr, request.Host = check.peer, check.host
			request.Header.Set(proxyHeader, "forged")
			request.Header.Set(clientHeader, "198.51.100.6")
			request.Header.Set("X-Forwarded-For", "198.51.100.6")
			request.Header.Set("Forwarded", "for=198.51.100.6")
			response := httptest.NewRecorder()
			gateway.ServeHTTP(response, request)
			if response.Code != check.status {
				t.Fatalf("got status %d, want %d", response.Code, check.status)
			}
			if response.Header().Get("X-Canabicultarr-Desktop-Run") != "" {
				t.Fatal("the public response exposes the local launcher identifier")
			}
		})
	}
}

func TestGeneratedCertificate(t *testing.T) {
	for _, host := range []string{"cana-desktop.test", "192.168.44.6"} {
		t.Run(host, func(t *testing.T) {
			directory := t.TempDir()
			if err := issueCertificate(host, directory); err != nil {
				t.Fatal(err)
			}
			files, err := os.ReadDir(directory)
			if err != nil || len(files) != 3 {
				t.Fatalf("expected only leaf certificate, leaf key and public root, got %v, %v", files, err)
			}
			der, err := os.ReadFile(filepath.Join(directory, "trust.cer"))
			if err != nil {
				t.Fatal(err)
			}
			root, err := x509.ParseCertificate(der)
			if err != nil {
				t.Fatal(err)
			}
			source, err := newCertificateSource(host, filepath.Join(directory, "server.pem"), filepath.Join(directory, "server.key"))
			if err != nil {
				t.Fatal(err)
			}
			pair := source.current
			if len(root.SubjectKeyId) == 0 || !bytes.Equal(pair.Leaf.AuthorityKeyId, root.SubjectKeyId) {
				t.Fatal("leaf certificate must identify the issuing key")
			}
			roots := x509.NewCertPool()
			roots.AddCert(root)
			if _, err := pair.Leaf.Verify(x509.VerifyOptions{Roots: roots, DNSName: host}); err != nil {
				t.Fatal(err)
			}
			if _, err := pair.Leaf.Verify(x509.VerifyOptions{Roots: roots, DNSName: "unrelated.test"}); err == nil {
				t.Fatal("certificate accepted an unrelated hostname")
			}
		})
	}
	for _, host := range []string{"8.8.8.8", "*.example.test"} {
		if err := issueCertificate(host, t.TempDir()); err == nil {
			t.Fatalf("certificate accepted invalid host %q", host)
		}
	}
}

func TestGatewayReloadsRenewedCertificate(t *testing.T) {
	t.Setenv("GODEBUG", "x509keypairleaf=0")
	const host = "desktop.example.test"
	active, renewed, other := t.TempDir(), t.TempDir(), t.TempDir()
	roots := x509.NewCertPool()
	for _, fixture := range []struct{ directory, host string }{
		{active, host}, {renewed, host}, {other, "other.example.test"},
	} {
		if err := issueCertificate(fixture.host, fixture.directory); err != nil {
			t.Fatal(err)
		}
		der, err := os.ReadFile(filepath.Join(fixture.directory, "trust.cer"))
		if err != nil {
			t.Fatal(err)
		}
		root, err := x509.ParseCertificate(der)
		if err != nil {
			t.Fatal(err)
		}
		roots.AddCert(root)
	}

	source, err := newCertificateSource(host, filepath.Join(active, "server.pem"), filepath.Join(active, "server.key"))
	if err != nil {
		t.Fatal(err)
	}
	gateway := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	gateway.TLS = &tls.Config{GetCertificate: source.getCertificate}
	gateway.StartTLS()
	defer gateway.Close()
	transport := &http.Transport{
		DisableKeepAlives: true,
		TLSClientConfig:   &tls.Config{RootCAs: roots, ServerName: host},
	}
	client := &http.Client{Transport: transport, Timeout: 5 * time.Second}
	readSerial := func() string {
		response, err := client.Get(gateway.URL)
		if err != nil {
			t.Fatal(err)
		}
		defer response.Body.Close()
		if response.StatusCode != http.StatusNoContent || len(response.TLS.VerifiedChains) == 0 {
			t.Fatal("expected a successful, verified TLS connection")
		}
		return response.TLS.PeerCertificates[0].SerialNumber.String()
	}
	replace := func(directory, name string) {
		contents, err := os.ReadFile(filepath.Join(directory, name))
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(active, name), contents, 0600); err != nil {
			t.Fatal(err)
		}
		source.mu.Lock()
		source.nextCheck = time.Time{}
		source.mu.Unlock()
	}

	original := readSerial()
	replace(renewed, "server.pem")
	if got := readSerial(); got != original {
		t.Fatal("an incomplete renewal replaced the active certificate")
	}
	replace(renewed, "server.key")
	replacement := readSerial()
	if replacement == original {
		t.Fatal("the gateway did not serve the renewed certificate")
	}
	replace(other, "server.pem")
	replace(other, "server.key")
	if got := readSerial(); got != replacement {
		t.Fatal("a certificate belonging to another installation replaced the active certificate")
	}
}
