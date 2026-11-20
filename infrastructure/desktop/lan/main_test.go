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
			pair, err := tls.LoadX509KeyPair(filepath.Join(directory, "server.pem"), filepath.Join(directory, "server.key"))
			if err != nil {
				t.Fatal(err)
			}
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
