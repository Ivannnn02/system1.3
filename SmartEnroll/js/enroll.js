        // ===============================
        // ELEMENTS
        // ===============================
        const dobInput = document.getElementById("dob");
        const ageInput = document.getElementById("age");
        const calendarBtn = document.getElementById("calendarBtn");
        const picker = document.getElementById("dobPicker");

        const monthLabel = document.getElementById("monthLabel");
        const yearLabel = document.getElementById("yearLabel");

        const provinceSelect = document.getElementById("province");
        const municipalitySelect = document.getElementById("municipality");
        const barangaySelect = document.getElementById("barangay");

        const submitBtn = document.getElementById("submitBtn");
        const summaryModal = document.getElementById("summaryModal");
        const summaryContent = document.getElementById("summaryContent");
        const confirmBtn = document.getElementById("confirmSubmit");
        const cancelBtn = document.getElementById("cancelSubmit");

        const form = document.getElementById("enrollmentForm");
        // ===============================
        // FORMAT VALIDATION FUNCTIONS
        // ===============================
        function isValidEmail(email) {
            // Simple email regex
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function isValidContact(contact) {
            // Philippine mobile number format: starts with 09, 11 digits
            return /^09\d{9}$/.test(contact);
        }
        let view = "days";
        let selectedDate = new Date();


        // ======================================================
        // DATE PICKER
        // ======================================================
        calendarBtn.addEventListener("click", (e) => {
            e.stopPropagation();

            if (picker.style.display === "block") {
                picker.style.display = "none";
                calendarBtn.style.visibility = "visible";
            } else {
                picker.style.display = "block";
                calendarBtn.style.visibility = "hidden";
                render();
            }
        });

        document.addEventListener("click", (e) => {
            if (!picker.contains(e.target) && !calendarBtn.contains(e.target)) {
                picker.style.display = "none";
                calendarBtn.style.visibility = "visible";
            }
        });

        monthLabel.onclick = () => { view = "months"; render(); };
        yearLabel.onclick = () => { view = "years"; render(); };

        function render() {
            updateHeader();

            const columns = picker.querySelector(".picker-columns");
            const monthCol = picker.querySelector(".month-col");
            const yearCol = picker.querySelector(".year-col");

            columns.style.display = view === "days" ? "none" : "flex";

            monthCol.classList.remove("show");
            yearCol.classList.remove("show");

            if (view === "months") monthCol.classList.add("show");
            if (view === "years") yearCol.classList.add("show");

            if (view === "days") renderDays();
            if (view === "months") renderMonths();
            if (view === "years") renderYears();
        }

        function updateHeader() {
            const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
            monthLabel.textContent = months[selectedDate.getMonth()];
            yearLabel.textContent = selectedDate.getFullYear();
        }

        function renderMonths() {
            const col = picker.querySelector(".month-col");
            col.innerHTML = "";

            const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

            months.forEach((m, i) => {
                const div = document.createElement("div");
                div.textContent = m;
                if (i === selectedDate.getMonth()) div.classList.add("active");

                div.onclick = () => {
                    selectedDate.setMonth(i);
                    view = "days";
                    render();
                };
                col.appendChild(div);
            });
        }

        function renderYears() {
            const col = picker.querySelector(".year-col");
            col.innerHTML = "";

            for (let y = new Date().getFullYear(); y >= 1920; y--) {
                const div = document.createElement("div");
                div.textContent = y;

                if (y === selectedDate.getFullYear()) div.classList.add("active");

                div.onclick = () => {
                    selectedDate.setFullYear(y);
                    view = "days";
                    render();
                };

                col.appendChild(div);
            }
        }

        function renderDays() {
            const grid = picker.querySelector(".day-grid");
            grid.innerHTML = "";

            const year = selectedDate.getFullYear();
            const month = selectedDate.getMonth();
            const days = new Date(year, month + 1, 0).getDate();

            for (let d = 1; d <= days; d++) {
                const div = document.createElement("div");
                div.textContent = d;

                div.onclick = () => {
                    selectedDate.setDate(d);
                    setDate(selectedDate);
                    picker.style.display = "none";
                    calendarBtn.style.visibility = "visible";
                };

                grid.appendChild(div);
            }
        }

        function setDate(date) {
            dobInput.value =
                String(date.getMonth() + 1).padStart(2, "0") + "/" +
                String(date.getDate()).padStart(2, "0") + "/" +
                date.getFullYear();

            calculateAge(date);
        }

        function calculateAge(dob) {
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();

            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
            ageInput.value = age;
        }


        // ======================================================
        // MANUAL DOB INPUT
        // ======================================================
        dobInput.addEventListener("input", () => {
            let value = dobInput.value.replace(/\D/g, "").substring(0, 8);

            if (value.length >= 5)
                dobInput.value = value.slice(0,2)+"/"+value.slice(2,4)+"/"+value.slice(4);
            else if (value.length >= 3)
                dobInput.value = value.slice(0,2)+"/"+value.slice(2);
            else
                dobInput.value = value;
        });

        dobInput.addEventListener("keydown", (e) => {
            if (e.key === "Enter") {
                e.preventDefault();

                const parts = dobInput.value.split("/");
                if (parts.length === 3) {
                    const manualDate = new Date(parts[2], parts[0]-1, parts[1]);
                    if (!isNaN(manualDate)) calculateAge(manualDate);
                }
            }
        });


        // ======================================================
        // ADDRESS (PSGC API)
        // ======================================================
        fetch("https://psgc.gitlab.io/api/provinces/")
        .then(r => r.json())
        .then(data => {
            data.sort((a, b) => a.name.localeCompare(b.name));

            data.forEach(p => {

                const opt = document.createElement("option");
                opt.text = p.name;
                opt.value = p.code; // use CODE for API
                provinceSelect.appendChild(opt);

            });

        });

        provinceSelect.addEventListener("change", () => {

            const provinceCode = provinceSelect.value;
            const provinceName = provinceSelect.selectedOptions[0].text;

            // STORE NAME in hidden input
            document.getElementById("province_name").value = provinceName;

            municipalitySelect.innerHTML = `<option value="">Select Municipality</option>`;
            barangaySelect.innerHTML = `<option value="">Select Barangay</option>`;
            municipalitySelect.disabled = true;
            barangaySelect.disabled = true;

            if (!provinceCode) return;

            fetch(`https://psgc.gitlab.io/api/provinces/${provinceCode}/cities-municipalities/`)
            .then(r => r.json())
            .then(data => {

                data.forEach(m => {

                    const opt = document.createElement("option");
                    opt.text = m.name;
                    opt.value = m.code; // use code for API
                    municipalitySelect.appendChild(opt);

                });

                municipalitySelect.disabled = false;
            });

        });
        municipalitySelect.addEventListener("change", () => {

            const municipalityCode = municipalitySelect.value;
            const municipalityName = municipalitySelect.selectedOptions[0].text;

            // STORE NAME in hidden input
            document.getElementById("municipality_name").value = municipalityName;

            barangaySelect.innerHTML = `<option value="">Select Barangay</option>`;
            barangaySelect.disabled = true;

            if (!municipalityCode) return;

            fetch(`https://psgc.gitlab.io/api/cities-municipalities/${municipalityCode}/barangays/`)
            .then(r => r.json())
            .then(data => {

                data.forEach(b => {
                    barangaySelect.appendChild(new Option(b.name, b.name));
                });

                barangaySelect.disabled = false;
            });

        });

        // ======================================================
        // GUARDIAN AUTO-FILL
        // ======================================================
        const guardianRadios = document.querySelectorAll('input[name="guardian_type"]');

        const guardianFields = {
            lname: document.querySelector('input[name="guardian_lname"]'),
            fname: document.querySelector('input[name="guardian_fname"]'),
            mname: document.querySelector('input[name="guardian_mname"]'),
            occ: document.querySelector('input[name="guardian_occ"]'),
            contact: document.querySelector('input[name="guardian_contact"]')
        };

        const father = {
            lname: document.querySelector('input[name="father_lname"]'),
            fname: document.querySelector('input[name="father_fname"]'),
            mname: document.querySelector('input[name="father_mname"]'),
            occ: document.querySelector('input[name="father_occ"]'),
            contact: document.querySelector('input[name="father_contact"]')
        };

        const mother = {
            lname: document.querySelector('input[name="mother_lname"]'),
            fname: document.querySelector('input[name="mother_fname"]'),
            mname: document.querySelector('input[name="mother_mname"]'),
            occ: document.querySelector('input[name="mother_occ"]'),
            contact: document.querySelector('input[name="mother_contact"]')
        };

        guardianRadios.forEach(radio => {
            radio.addEventListener("change", () => {

                if (radio.value === "father") fillGuardian(father, true);
                else if (radio.value === "mother") fillGuardian(mother, true);
                else fillGuardian(null, false);

            });
        });

        function fillGuardian(source, readonly) {

            Object.values(guardianFields).forEach(f => {
                f.value = source ? source[Object.keys(guardianFields)
                    .find(k => guardianFields[k] === f)].value : "";
                f.readOnly = readonly;
            });
        }


        // ======================================================
        // MEDICATION FIELD ENABLE
        // ======================================================
        const medicationRadios = document.querySelectorAll('input[name="medication"]');
        const medicationInput = document.getElementById("medication_details");

        medicationRadios.forEach(radio => {
            radio.addEventListener("change", () => {
                medicationInput.disabled = radio.value !== "yes";
                if (radio.value !== "yes") medicationInput.value = "";
            });
        });


        // ======================================================
        // COMPLETION DATE AUTO TODAY
        // ======================================================
        document.addEventListener("DOMContentLoaded", () => {
            
            const completionDateInput = document.getElementById("completionDate");
            const display = document.getElementById("completionDisplay");

            function formatDate(date) {
                return date.toLocaleDateString("en-US");
            }

            function setToday() {
                const today = new Date();
                today.setMinutes(today.getMinutes() - today.getTimezoneOffset());

                const formattedISO = today.toISOString().split("T")[0];

                completionDateInput.value = formattedISO;
                completionDateInput.max = formattedISO;
                display.value = formatDate(today);
            }

            // Set default to today
            setToday();

            // When user clicks display field â†’ open date picker
            display.addEventListener("click", () => {
                completionDateInput.showPicker();
            });

            // When date changes
            completionDateInput.addEventListener("change", () => {
                const selectedDate = new Date(completionDateInput.value);

                const today = new Date();
                today.setHours(0, 0, 0, 0);

                // Prevent future dates manually (extra safety)
                if (selectedDate > today) {
                    setToday();
                    return;
                }

                display.value = formatDate(selectedDate);
            });
        });


        // ======================================================
        // SUBMIT â†’ VALIDATE â†’ SUMMARY
        // ======================================================
        // ======================================================
        // FINAL VALIDATE FORM (ONLY ONE)
        // ======================================================
        submitBtn.addEventListener("click", () => {

            let valid = true;

            document.querySelectorAll("input, select").forEach(el => {

                // âœ… SKIP EXTENSION NAME ONLY
                if (el.name === "learner_ext") return;

                if (
                    el.disabled ||
                    el.type === "hidden" ||
                    el.type === "radio" ||
                    el.readOnly
                ) return;

                if (!el.value.trim()) {
                    el.classList.add("input-error");
                    valid = false;
                } else {
                    el.classList.remove("input-error");
                }
        if (medsYes.checked && !medsInput.value.trim()) {
            medsInput.classList.add("input-error");
            valid = false;
        } else {
            medsInput.classList.remove("input-error");
        }
            });

            // Grade level radio validation
            if (!document.querySelector('input[name="grade_level"]:checked')) {
                valid = false;
            }

            if (!valid) {
                showValidationPopup();
                return;
            }

            buildSummary();
            summaryModal.style.display = "flex";

        }); 

        function getMedicationValue() {
            const selected = document.querySelector('input[name="medication"]:checked');
            return selected
                ? selected.value.charAt(0).toUpperCase() + selected.value.slice(1)
                : "No";
        }   
        // ======================================================
        // SUMMARY
        // ======================================================
        function buildSummary() {

            const summaryContent = document.getElementById("summaryContent");

            function val(name) {
                const elements = document.querySelectorAll(`[name="${name}"]`);
                if (!elements.length) return "-";

                const first = elements[0];

                if (first.type === "radio") {
                    const checked = document.querySelector(`[name="${name}"]:checked`);
                    return checked && checked.value ? checked.value : "-";
                }

                return first.value ? first.value : "-";
            }

            function selectText(id) {
                const el = document.getElementById(id);
                return el?.selectedOptions[0]?.text || "-";
            }

            summaryContent.innerHTML = `

            <div class="summary-section">
                <h3>LEARNER INFORMATION</h3>

                ${row("Grade Level", val("grade_level"))}
                ${row("First Name", val("learner_fname"))}
                ${row("Middle Name", val("learner_mname"))}
                ${row("Last Name", val("learner_lname"))}
                ${row("Extension Name", val("learner_ext"))}
                ${row("Age", val("age"))}
                ${row("Sex", val("sex"))}
                ${row("Date of Birth", val("dob"))}
                ${row("Email Address", val("email"))}
            </div>

            <div class="summary-section">
                <h3>ADDRESS</h3>

                ${row("Province", selectText("province"))}
                ${row("Municipality", selectText("municipality"))}
                ${row("Barangay", selectText("barangay"))}
            </div>

            <div class="summary-section">
                <h3>FATHER INFORMATION</h3>

                ${row("First Name", val("father_fname"))}
                ${row("Middle Name", val("father_mname"))}
                ${row("Last Name", val("father_lname"))}
                ${row("Occupation", val("father_occ"))}
                ${row("Contact", val("father_contact"))}
            </div>

            <div class="summary-section">
                <h3>MOTHER INFORMATION</h3>

                ${row("First Name", val("mother_fname"))}
                ${row("Middle Name", val("mother_mname"))}
                ${row("Last Name", val("mother_lname"))}
                ${row("Occupation", val("mother_occ"))}
                ${row("Contact", val("mother_contact"))}
            </div>

            <div class="summary-section">
                <h3>GUARDIAN INFORMATION</h3>

                ${row("First Name", val("guardian_fname"))}
                ${row("Middle Name", val("guardian_mname"))}
                ${row("Last Name", val("guardian_lname"))}
                ${row("Occupation", val("guardian_occ"))}
                ${row("Contact", val("guardian_contact"))}
            </div>

        <div class="summary-section">
            <h3>D. LEARNERS WITH SPECIAL EDUCATION NEEDS</h3>

            ${row("Special Education Needs", val("special_needs"))}
            ${row("Takes Medication", getMedicationValue())}

            ${getMedicationValue() === "Yes"
                ? row("Medication Details", val("medication_details"))
                : ""}
        </div>

        <div class="summary-section">
            <h3>IN CASE OF EMERGENCY</h3>

            ${row("Contact 1 Name", val("emergency1_name"))}
            ${row("Contact 1 Relationship", val("emergency1_relationship"))}
            ${row("Contact 1 Phone", val("emergency1_contact"))}

            ${row("Contact 2 Name", val("emergency2_name"))}
            ${row("Contact 2 Relationship", val("emergency2_relationship"))}
            ${row("Contact 2 Phone", val("emergency2_contact"))}

            ${row("Contact 3 Name", val("emergency3_name"))}
            ${row("Contact 3 Relationship", val("emergency3_relationship"))}
            ${row("Contact 3 Phone", val("emergency3_contact"))}
        </div>
            `;
        }

        function row(label, value) {
            return `
                <div class="summary-row">
                    <div class="summary-label">${label}:</div>
                    <div class="summary-value">${value}</div>
                </div>
            `;
        }


        // ======================================================
        // CONFIRM SUBMIT
        // ======================================================


        confirmBtn.addEventListener("click", function () {

            window.scrollTo({ top: 0, behavior: "smooth" });

            const formData = new FormData(form);

            fetch("save_enrollment.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.text())
            .then(data => {

                if (data.trim() === "success") {

                    // CLOSE SUMMARY
                    summaryModal.style.display = "none";

                    // SHOW SUCCESS POPUP
                    showSuccessPopup();

                } else {
                    alert("Error: " + data);
                }

            })
            .catch(error => {
                alert("Something went wrong.");
            });

        });

        cancelBtn.addEventListener("click", () => {
            summaryModal.style.display = "none";
        });
        const validationPopup = document.getElementById("validationPopup");
        const okValidation = document.getElementById("okValidation");
        const popupIcon = document.getElementById("popupIcon");

        /* SHOW POPUP WITH LOGO â†’ X ANIMATION */
        function showValidationPopup() {

            validationPopup.classList.add("active");

            // Reset animation
            popupIcon.classList.remove("show-x");

            // After short delay â†’ morph into X
            setTimeout(() => {
                popupIcon.classList.add("show-x");
            }, 700);
        }

        /* HIDE POPUP */
        function hideValidationPopup() {
            validationPopup.classList.remove("active");
        }

        okValidation.onclick = hideValidationPopup;

        validationPopup.addEventListener("click", (e) => {
            if (e.target === validationPopup) hideValidationPopup();
        });
        // VALIDATE FORM
        // ======================================================
        // FINAL VALIDATE FORM (ONLY ONE)
        // ======================================================
    const gradeLevelInputs = document.querySelectorAll('input[name="grade_level"]');

    function setGradeLevelErrorState(hasError) {
        gradeLevelInputs.forEach((radio) => {
            const label = radio.closest("label");
            if (label) label.classList.toggle("radio-error", hasError);
        });
    }

    gradeLevelInputs.forEach((radio) => {
        radio.addEventListener("change", () => {
            if (document.querySelector('input[name="grade_level"]:checked')) {
                setGradeLevelErrorState(false);
            }
        });
    });

    submitBtn.addEventListener("click", () => {
        let valid = true;

        // Reset previous errors and messages
        document.querySelectorAll("input, select").forEach(el => {
            el.classList.remove("input-error");
            const errorEl = el.nextElementSibling;
            if (errorEl && errorEl.classList.contains("error-message")) {
                errorEl.remove();
            }
        });
        setGradeLevelErrorState(false);

        // 1ï¸âƒ£ Required fields
        document.querySelectorAll("input, select").forEach(el => {
            if (el.name === "learner_ext") return;
            if (el.disabled || el.type === "hidden" || el.type === "radio" || el.readOnly) return;

            if (!el.value.trim()) {
                el.classList.add("input-error");
                valid = false;
                showInlineError(el, "This field is required");
            }
        });

        // 2ï¸âƒ£ Grade level radio validation
        if (!document.querySelector('input[name="grade_level"]:checked')) {
            valid = false;
            setGradeLevelErrorState(true);
        }

        // 3ï¸âƒ£ Email validation
        const emailField = document.querySelector('input[name="email"]');
        if (emailField.value && !isValidEmail(emailField.value)) {
            emailField.classList.add("input-error");
            showInlineError(emailField, "Enter a valid email format");
            valid = false;
        }

        // 4ï¸âƒ£ Contact number validation
        const contactFields = [
            'father_contact',
            'mother_contact',
            'guardian_contact',
            'emergency1_contact',
            'emergency2_contact',
            'emergency3_contact'
        ];

        contactFields.forEach(name => {
            const field = document.querySelector(`input[name="${name}"]`);
            if (field.value && !isValidContact(field.value)) {
                field.classList.add("input-error");
                showInlineError(field, "Must be 11 digits starting with 09");
                valid = false;
            }
        });

        // 5ï¸âƒ£ Medication field validation
        if (medsYes.checked && !medsInput.value.trim()) {
            medsInput.classList.add("input-error");
            showInlineError(medsInput, "Please provide medication details");
            valid = false;
        }

        // âŒ If any error, stop â†’ show popup
        if (!valid) {
            showFormatErrorPopup("Please correct all highlighted fields before continuing.");
            summaryModal.style.display = "none";
            return;
        }

        // âœ… All validations pass â†’ show confirmation summary
        buildSummary();
        summaryModal.style.display = "flex";
    });

    // Function to show inline error message
    function showInlineError(input, message) {
        const errorEl = document.createElement("div");
        errorEl.classList.add("error-message");
        errorEl.style.color = "red";
        errorEl.style.fontSize = "12px";
        errorEl.textContent = message;
        input.insertAdjacentElement("afterend", errorEl);
    }
        function showFormatErrorPopup(message) {
            const validationPopup = document.getElementById("validationPopup");
            const popupText = validationPopup.querySelector("p");
            popupText.textContent = message;

            validationPopup.classList.add("active");
            popupIcon.classList.remove("show-x");
            setTimeout(() => popupIcon.classList.add("show-x"), 700);
        }

        const medsYes = document.querySelector('input[name="medication"][value="yes"]');
        const medsNo = document.querySelector('input[name="medication"][value="no"]');
        const medsInput = document.getElementById("medication_details");

        function toggleMedication() {

            if (medsYes.checked) {
                medsInput.disabled = false;
                medsInput.required = true;
            } else {
                medsInput.disabled = true;
                medsInput.required = false;
                medsInput.value = "";
            }
        }

        // Run on load
        toggleMedication();

        medsYes.addEventListener("change", toggleMedication);
        medsNo.addEventListener("change", toggleMedication);
        function resetFormKeepDate() {

            const completionDateValue = document.getElementById("completionDate").value;

            form.reset();

            // Restore completion date
            document.getElementById("completionDate").value = completionDateValue;

            // Reset medication field properly
            toggleMedication();

        }
        const successPopup = document.getElementById("successPopup");
        const successIcon = document.getElementById("successIcon");
        const closeSuccess = document.getElementById("closeSuccess");

        function showSuccessPopup() {

            successPopup.classList.add("active");

            // Reset state
            successIcon.classList.remove("show-check");

            // After delay â†’ morph logo into check
            setTimeout(() => {
                successIcon.classList.add("show-check");
            }, 600);
        }

        closeSuccess.addEventListener("click", () => {
            successPopup.classList.remove("active");
            successIcon.classList.remove("show-check");

            resetFormKeepDate(); // keeps completion date
        });
// Default guardian type radio to "other" on load.
document.addEventListener('DOMContentLoaded', function () {
  const radios = document.querySelectorAll('input[name="guardian_type"]');
  if (!radios.length) return;

  const alreadyChecked = Array.from(radios).some(function (r) { return r.checked; });
  if (alreadyChecked) return;

  const other = Array.from(radios).find(function (r) {
    return (r.value || '').toLowerCase() === 'other';
  });

  if (other) {
    other.checked = true;
    other.dispatchEvent(new Event('change', { bubbles: true }));
  }
});

