package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

var dnsLabel = regexp.MustCompile("^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")

func issueCertificate(host, directory string) error {
	ip := net.ParseIP(host)
	if ip == nil {
		if len(host) > 253 {
			return errors.New("invalid certificate hostname")
		}
		for _, label := range strings.Split(strings.ToLower(host), ".") {
			if !dnsLabel.MatchString(label) {
				return errors.New("invalid certificate hostname")
			}
		}
	} else if ip.To4() == nil || !ip.IsPrivate() {
		return errors.New("certificate IP address must be private IPv4")
	}

	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	caSerial, err := certificateSerial()
	if err != nil {
		return err
	}
	serverSerial, err := certificateSerial()
	if err != nil {
		return err
	}
	now := time.Now()
	root := &x509.Certificate{
		SerialNumber:          caSerial,
		Subject:               pkix.Name{CommonName: "Canabicultarr Desktop " + caSerial.Text(16)[:8]},
		NotBefore:             now.Add(-5 * time.Minute),
		NotAfter:              now.AddDate(2, 0, 0),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		IsCA:                  true,
		BasicConstraintsValid: true,
		MaxPathLenZero:        true,
	}
	certificate := &x509.Certificate{
		SerialNumber:          serverSerial,
		Subject:               pkix.Name{CommonName: host},
		NotBefore:             root.NotBefore,
		NotAfter:              now.Add(365 * 24 * time.Hour),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	if ip == nil {
		certificate.DNSNames = []string{host}
	} else {
		certificate.IPAddresses = []net.IP{ip}
	}
	caDER, err := x509.CreateCertificate(rand.Reader, root, root, &caKey.PublicKey, caKey)
	if err != nil {
		return err
	}
	// The encoded CA includes the generated subject key identifier used by the leaf.
	issuer, err := x509.ParseCertificate(caDER)
	if err != nil {
		return err
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, certificate, issuer, &serverKey.PublicKey, caKey)
	if err != nil {
		return err
	}
	// The root key only exists in this process. The Desktop runtime only needs the leaf key.
	keyDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		return err
	}
	for name, contents := range map[string][]byte{
		"trust.cer":  caDER,
		"server.pem": pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER}),
		"server.key": pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}),
	} {
		file, err := os.OpenFile(filepath.Join(directory, name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if err != nil {
			return err
		}
		_, writeErr := file.Write(contents)
		if err := errors.Join(writeErr, file.Close()); err != nil {
			return err
		}
	}
	return nil
}

func certificateSerial() (*big.Int, error) {
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	if err != nil {
		return nil, err
	}
	return serial.SetBit(serial, 127, 1), nil
}
