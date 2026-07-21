/**
 * UNIWEB — Country / State / District / City auto-fill (international)
 */
function initAddressPicker(rootId) {
    const root = document.getElementById(rootId);
    if (!root) return;

    const LOCAL_COUNTRIES = ['India', 'United States', 'United Kingdom', 'United Arab Emirates', 'Canada', 'Australia', 'Singapore', 'Germany', 'France', 'Nepal', 'Bangladesh', 'Sri Lanka'];

    const INDIA_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana',
        'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
        'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
        'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi',
        'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    const fetchWithTimeout = (url, options, timeoutMs) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs || 6000);
        return fetch(url, { ...(options || {}), signal: controller.signal })
            .finally(() => clearTimeout(timer));
    };

    const country = root.querySelector('[data-addr="country"]');
    let state = root.querySelector('[data-addr="state"]');
    let district = root.querySelector('[data-addr="district"]');
    const city = root.querySelector('[data-addr="city"]');
    const postal = root.querySelector('[data-addr="postal"]');
    const phoneCode = root.querySelector('[data-addr="phone_code"]');
    const streetField = root.querySelector('[data-addr="street"]');
    const suggestBox = root.querySelector('[data-addr="suggestions"]');
    const useLocationBtn = root.querySelector('[data-addr="use-location"]');
    const locStatus = root.querySelector('[data-addr="loc-status"]');

    if (!country || !state) return;

    const saved = {
        country: country.dataset.value || '',
        state: state.dataset.value || '',
        district: district?.dataset.value || '',
        city: city?.dataset.value || '',
    };

    function setLoading(sel, msg) {
        sel.innerHTML = `<option value="">${msg}</option>`;
        sel.disabled = true;
    }

    function fillSelect(sel, items, placeholder, selected) {
        sel.innerHTML = `<option value="">${placeholder}</option>`;
        items.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item;
            if (item === selected) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.disabled = false;
    }

    function convertToTextInput(el, placeholder, value) {
        if (!el || el.tagName !== 'SELECT') {
            if (el) el.disabled = false;
            return el;
        }
        const input = document.createElement('input');
        input.type = 'text';
        input.name = el.name;
        input.className = el.className;
        if (el.required) input.required = true;
        const addr = el.getAttribute('data-addr');
        if (addr) input.setAttribute('data-addr', addr);
        if (el.dataset.value) input.setAttribute('data-value', el.dataset.value);
        input.placeholder = placeholder || '';
        input.value = value || el.dataset.value || '';
        el.replaceWith(input);
        return input;
    }

    function ensureValue(el, value) {
        if (!el || !value) return;
        if (el.tagName === 'SELECT') {
            if (![...el.options].some((o) => o.value === value)) {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = value;
                el.appendChild(opt);
            }
            el.value = value;
        } else {
            el.value = value;
        }
    }

    async function postJson(url, body) {
        const res = await fetchWithTimeout(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        }, 6000);
        return res.json();
    }

    async function loadCountries() {
        setLoading(country, 'Loading countries...');
        try {
            const data = await fetchWithTimeout('https://countriesnow.space/api/v0.1/countries', {}, 6000).then((r) => r.json());
            const list = (data.data || []).map((c) => c.country).sort();
            if (!list.length) throw new Error('empty');
            fillSelect(country, list, 'Select Country', saved.country || 'India');
        } catch (e) {
            fillSelect(country, LOCAL_COUNTRIES, 'Select Country', saved.country || 'India');
        }
        if (country.value) await loadStates(country.value, true);
    }

    async function loadStates(countryName, restore) {
        if (state.tagName !== 'SELECT') {
            if (restore) ensureValue(state, saved.state);
            return;
        }
        setLoading(state, 'Loading states...');
        if (district && district.tagName === 'SELECT') setLoading(district, 'Select state first');
        try {
            const data = await postJson('https://countriesnow.space/api/v0.1/countries/states', { country: countryName });
            const list = (data.data?.states || []).map((s) => s.name).sort();
            if (!list.length) throw new Error('empty');
            fillSelect(state, list, 'Select State / Province', restore ? saved.state : '');
            if (restore && saved.state) await loadDistricts(countryName, saved.state, true);
        } catch (e) {
            if (countryName === 'India') {
                fillSelect(state, INDIA_STATES, 'Select State / Province', restore ? saved.state : '');
                if (restore && saved.state) await loadDistricts(countryName, saved.state, true);
            } else {
                state = convertToTextInput(state, 'Type your state / province', restore ? saved.state : '');
                district = convertToTextInput(district, 'Type your district / area', restore ? saved.district : '');
            }
        }
    }

    async function loadDistricts(countryName, stateName, restore) {
        if (!district) return;
        if (district.tagName !== 'SELECT') {
            if (restore) ensureValue(district, saved.district);
            return;
        }
        setLoading(district, 'Loading districts...');
        try {
            const data = await postJson('https://countriesnow.space/api/v0.1/countries/state/cities', { country: countryName, state: stateName });
            const list = (data.data || []).sort();
            if (!list.length) throw new Error('empty');
            fillSelect(district, list.slice(0, 500), 'Select District / Area', restore ? saved.district : '');
            if (restore && saved.city && city) city.value = saved.city;
        } catch (e) {
            district = convertToTextInput(district, 'Type your district / area', restore ? saved.district : '');
            if (restore && saved.city && city) city.value = saved.city;
        }
    }

    country.addEventListener('change', () => {
        const c = country.value;
        if (phoneCode && c === 'India') phoneCode.value = '+91';
        if (phoneCode && c === 'United States') phoneCode.value = '+1';
        if (phoneCode && c === 'United Kingdom') phoneCode.value = '+44';
        if (phoneCode && c === 'United Arab Emirates') phoneCode.value = '+971';
        if (c) loadStates(c, false);
    });

    state.addEventListener('change', () => {
        if (country.value && state.value) loadDistricts(country.value, state.value, false);
    });

    if (district && city) {
        district.addEventListener('change', () => {
            if (district.value && !city.value) city.value = district.value;
        });
    }

    // --- Google-Maps-style autofill: type a place name, or use device location ---
    // Uses OpenStreetMap Nominatim (free, no API key) for forward/reverse geocoding.
    function setStatus(msg, isError) {
        if (!locStatus) return;
        locStatus.textContent = msg;
        locStatus.className = 'text-[11px] mt-1 ' + (isError ? 'text-red-400' : 'text-brand-400');
    }

    async function applyGeocodedAddress(addr) {
        if (!addr) return;
        const countryName = addr.country || '';
        const stateName = addr.state || '';
        const districtName = addr.state_district || addr.county || addr.city_district || '';
        const cityName = addr.city || addr.town || addr.village || addr.municipality || addr.suburb || districtName || '';
        const postcode = addr.postcode || '';

        if (countryName) {
            ensureValue(country, countryName);
            country.dispatchEvent(new Event('change'));
        }
        if (stateName) {
            await loadStates(countryName, false);
            ensureValue(state, stateName);
        }
        if (districtName && district) {
            await loadDistricts(countryName, stateName, false);
            ensureValue(district, districtName);
        }
        if (cityName && city) city.value = cityName;
        if (postcode && postal) postal.value = postcode;
    }

    if (useLocationBtn) {
        useLocationBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                setStatus('Geolocation not supported on this browser.', true);
                return;
            }
            setStatus('Detecting your location...');
            navigator.geolocation.getCurrentPosition(async (pos) => {
                try {
                    const { latitude, longitude } = pos.coords;
                    const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}&addressdetails=1`, {
                        headers: { 'Accept-Language': 'en' },
                    });
                    const data = await res.json();
                    if (streetField && !streetField.value && data.display_name) streetField.value = data.display_name;
                    await applyGeocodedAddress(data.address);
                    setStatus('Location detected and filled in ✓');
                } catch (e) {
                    setStatus('Could not detect address from location.', true);
                }
            }, () => {
                setStatus('Location permission denied. Please fill manually.', true);
            }, { timeout: 10000 });
        });
    }

    if (streetField && suggestBox) {
        let debounceTimer = null;
        streetField.addEventListener('input', () => {
            const q = streetField.value.trim();
            clearTimeout(debounceTimer);
            if (q.length < 4) {
                suggestBox.classList.add('hidden');
                suggestBox.innerHTML = '';
                return;
            }
            debounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=${encodeURIComponent(q)}`, {
                        headers: { 'Accept-Language': 'en' },
                    });
                    const results = await res.json();
                    if (!Array.isArray(results) || !results.length) {
                        suggestBox.classList.add('hidden');
                        suggestBox.innerHTML = '';
                        return;
                    }
                    suggestBox.innerHTML = results.map((r, i) =>
                        `<div data-idx="${i}" class="px-3 py-2 text-sm text-gray-200 hover:bg-brand-500/10 cursor-pointer border-b border-gray-800 last:border-0">${r.display_name}</div>`
                    ).join('');
                    suggestBox.classList.remove('hidden');
                    suggestBox.querySelectorAll('[data-idx]').forEach((el) => {
                        el.addEventListener('click', async () => {
                            const r = results[parseInt(el.dataset.idx, 10)];
                            streetField.value = r.display_name;
                            suggestBox.classList.add('hidden');
                            setStatus('Filling City / State / Country...');
                            await applyGeocodedAddress(r.address);
                            setStatus('Address filled in ✓');
                        });
                    });
                } catch (e) {
                    suggestBox.classList.add('hidden');
                }
            }, 500);
        });
        document.addEventListener('click', (e) => {
            if (!suggestBox.contains(e.target) && e.target !== streetField) suggestBox.classList.add('hidden');
        });
    }

    loadCountries();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-address-picker]').forEach((el) => initAddressPicker(el.id));
});
