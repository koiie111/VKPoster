// Sequential resumable upload shared by the media library and post editor. The server confirms each offset.
(function () {
    'use strict';

    function token() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
    function read(response) {
        return response.json().catch(function () { return null; }).then(function (data) {
            if (!response.ok || !data || !data.ok) {
                throw new Error(data && data.errors && data.errors[0] ? data.errors[0] : 'Не удалось загрузить видео. Попробуйте ещё раз.');
            }
            return data;
        });
    }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': token(), 'Accept': 'application/json' }, body: body, credentials: 'same-origin' }).then(read);
    }
    function form(values) {
        var data = new FormData();
        Object.keys(values).forEach(function (key) { data.append(key, values[key]); });
        return data;
    }
    // WebCrypto has no streaming digest. Keep memory bounded while hashing a 1 GiB file.
    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];
    function rotr(x, n) { return (x >>> n) | (x << (32 - n)); }
    function sha256() {
        var state = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
        var block = new Uint8Array(64);
        var words = new Uint32Array(64);
        var filled = 0;
        var total = 0;
        function compress() {
            var i;
            for (i = 0; i < 16; i += 1) {
                var j = i * 4;
                words[i] = ((block[j] << 24) | (block[j + 1] << 16) | (block[j + 2] << 8) | block[j + 3]) >>> 0;
            }
            for (i = 16; i < 64; i += 1) {
                var x = words[i - 15], y = words[i - 2];
                words[i] = (words[i - 16] + (rotr(x, 7) ^ rotr(x, 18) ^ (x >>> 3)) + words[i - 7] + (rotr(y, 17) ^ rotr(y, 19) ^ (y >>> 10))) >>> 0;
            }
            var a = state[0], b = state[1], c = state[2], d = state[3], e = state[4], f = state[5], g = state[6], h = state[7];
            for (i = 0; i < 64; i += 1) {
                var t1 = (h + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + words[i]) >>> 0;
                var t2 = ((rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) >>> 0;
                h = g; g = f; f = e; e = (d + t1) >>> 0; d = c; c = b; b = a; a = (t1 + t2) >>> 0;
            }
            state[0] = (state[0] + a) >>> 0; state[1] = (state[1] + b) >>> 0;
            state[2] = (state[2] + c) >>> 0; state[3] = (state[3] + d) >>> 0;
            state[4] = (state[4] + e) >>> 0; state[5] = (state[5] + f) >>> 0;
            state[6] = (state[6] + g) >>> 0; state[7] = (state[7] + h) >>> 0;
        }
        function update(bytes) {
            total += bytes.length;
            var offset = 0;
            while (offset < bytes.length) {
                var take = Math.min(64 - filled, bytes.length - offset);
                block.set(bytes.subarray(offset, offset + take), filled);
                filled += take; offset += take;
                if (filled === 64) { compress(); filled = 0; }
            }
        }
        function finish() {
            var bits = total * 8;
            update(new Uint8Array([0x80]));
            if (filled > 56) { update(new Uint8Array(64 - filled)); }
            if (filled < 56) { update(new Uint8Array(56 - filled)); }
            for (var i = 0; i < 8; i += 1) { block[56 + i] = Math.floor(bits / Math.pow(2, (7 - i) * 8)) & 255; }
            compress();
            return Array.prototype.map.call(state, function (value) { return value.toString(16).padStart(8, '0'); }).join('');
        }
        return { update: update, finish: finish };
    }
    async function digest(file) {
        var hash = sha256();
        for (var at = 0; at < file.size; at += 8 * 1024 * 1024) {
            hash.update(new Uint8Array(await file.slice(at, at + 8 * 1024 * 1024).arrayBuffer()));
        }
        return hash.finish();
    }
    function pause(ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); }

    window.VKVideoUpload = {
        upload: async function (file, uploadUrl, folder, progress) {
            progress(0, 'Проверяем видео…');
            var hash = await digest(file);
            var base = uploadUrl.replace(/\/upload$/, '/video-uploads');
            var key = 'video-upload:' + base + ':' + file.name + ':' + file.size + ':' + file.lastModified + ':' + hash;
            var id = window.localStorage.getItem(key);
            var state = null;
            if (id) {
                try { state = await fetch(base + '/' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(read); }
                catch (e) { window.localStorage.removeItem(key); id = null; }
            }
            if (!id) {
                state = await post(base, form({ name: file.name, size: file.size, sha256: hash }));
                id = state.id;
                window.localStorage.setItem(key, id);
            }
            var offset = state.offset;
            var chunkSize = state.chunk_bytes || 8 * 1024 * 1024;
            while (offset < file.size) {
                var end = Math.min(file.size, offset + chunkSize);
                var chunk = file.slice(offset, end);
                var tries = 0;
                while (true) {
                    try {
                        var answer = await post(base + '/' + encodeURIComponent(id) + '/chunks', form({ offset: offset, chunk: chunk }));
                        if (answer.offset <= offset && answer.offset !== end) { throw new Error('Сервер не подтвердил часть видео.'); }
                        offset = answer.offset;
                        progress(Math.round(offset * 100 / file.size), 'Загружаем видео…');
                        break;
                    } catch (error) {
                        tries += 1;
                        if (tries >= 3) { throw error; }
                        await pause(1000 * tries);
                        var confirmed = await fetch(base + '/' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(read);
                        offset = confirmed.offset;
                        if (offset >= end) { break; }
                    }
                }
            }
            progress(100, 'Сохраняем видео…');
            var result = await post(base + '/' + encodeURIComponent(id) + '/finish', form({ folder: folder || '' }));
            window.localStorage.removeItem(key);
            return result;
        },
    };
})();
