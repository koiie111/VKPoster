package jose

import (
	"encoding/base64"
	"encoding/json"
	"math/big"
	"strings"
	"testing"
)

func TestRejectOversizedRSAJWKBeforeValidation(t *testing.T) {
	encode := func(n *big.Int) string {
		return base64.RawURLEncoding.EncodeToString(n.Bytes())
	}
	prime := new(big.Int).Lsh(big.NewInt(1), 16383)
	prime.SetBit(prime, 0, 1)
	key := map[string]string{
		"kty": "RSA",
		"n":   encode(new(big.Int).Lsh(big.NewInt(1), 2048)),
		"e":   encode(big.NewInt(65537)),
		"d":   encode(big.NewInt(3)),
		"p":   encode(prime),
		"q":   encode(big.NewInt(3)),
	}
	data, err := json.Marshal(key)
	if err != nil {
		t.Fatal(err)
	}
	var parsed JSONWebKey
	if err := json.Unmarshal(data, &parsed); err == nil || !strings.Contains(err.Error(), "p * q != n") {
		t.Fatalf("expected bounded RSA parameter rejection, got %v", err)
	}
}
