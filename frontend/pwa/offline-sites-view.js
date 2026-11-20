for (const panel of document.querySelectorAll('[data-offline-sites]')) {
  const form = panel.querySelector('form');
  const field = panel.querySelector('input');
  const result = panel.querySelector('[data-offline-results]');
  const list = panel.querySelector('ul');
  const feedback = panel.querySelector('[role="status"]');
  const empty = panel.querySelector('[data-offline-empty]');
  const pending = panel.querySelector('[data-offline-pending]');
  const draftsOnly = panel.querySelector('[data-offline-drafts-only]');
  const draftReview = panel.querySelector('[data-offline-drafts]');
  const draftList = draftReview.querySelector('ul');
  const draftLabels = JSON.parse(draftReview.dataset.labels);
  const rejectionLabels = JSON.parse(draftReview.dataset.rejections);
  const truncated = panel.querySelector('[data-offline-truncated]');
  const submit = panel.querySelector('[type="submit"]');
  const journalForm = panel.querySelector('[data-offline-journal]');
  const journalCopy = panel.querySelector('[data-offline-journal-copy]');
  const journalList = journalCopy.querySelector('ul');
  const locale = panel.closest('[lang]').lang;
  const siteSelect = journalForm.elements.site;
  const queueStatus = panel.querySelector('[data-offline-queue-status]');
  const queueButton = journalForm.querySelector('button');
  const plantPanel = panel.querySelector('[data-offline-plants]');
  const phaseLabels = JSON.parse(plantPanel.dataset.phases);
  const plantSearch = plantPanel.querySelector('input');
  const plantList = plantPanel.querySelector('ul');
  const noPlants = plantPanel.querySelector('[data-offline-plants-empty]');
  const maintenanceForm = plantPanel.querySelector('[data-offline-maintenance]');
  const wateringForm = plantPanel.querySelector('[data-offline-watering]');
  const creationForm = plantPanel.querySelector('[data-offline-creation]');
  const photoForm = plantPanel.querySelector('[data-offline-photo]');
  const plantForms = [maintenanceForm, wateringForm, creationForm, photoForm];
  const plantStatuses = ['maintenance', 'watering', 'creation', 'photo'].map(kind => panel.querySelector('[data-offline-' + kind + '-status]'));
  const fertilizerFields = wateringForm.querySelector('[data-offline-fertilizers]');
  const fertilizerChoice = wateringForm.querySelector('[data-offline-fertilizer-choice]');
  const fertilizerToggle = wateringForm.elements.with_fertilizer;
  const wateringSubmit = wateringForm.querySelector('button');
  const morePlants = plantPanel.querySelector('[data-offline-plants-truncated]');
  const filesPanel = panel.querySelector('[data-offline-files]');
  const filesSelect = filesPanel.querySelector('select');
  const filePreview = filesPanel.querySelector('[data-offline-file-preview]');
  const fileDownload = filesPanel.querySelector('a');
  let fileUrl;
  let filesTimeout;

  function clearFilePreview() {
    if (fileUrl) URL.revokeObjectURL(fileUrl);
    fileUrl = undefined;
    filePreview.replaceChildren();
    fileDownload.removeAttribute('href');
    fileDownload.removeAttribute('download');
    fileDownload.hidden = true;
  }

  function clearFiles() {
    window.clearTimeout(filesTimeout);
    clearFilePreview();
    filesSelect.replaceChildren();
    filesPanel.open = false;
    filesPanel.hidden = true;
  }

  function showOfflineFile() {
    clearFilePreview();
    if (!filesPanel.open || !unlocked || unlocked.filesExpireAt <= Date.now()) return;
    const file = unlocked.files.find(item => item.id === filesSelect.value);
    if (!file) return;
    const bytes = decodeOfflineBytes(file.base64);
    fileUrl = URL.createObjectURL(new Blob([bytes], { type: file.mediaType }));
    if (['image/jpeg', 'image/png', 'image/webp'].includes(file.mediaType)) {
      const img = document.createElement('img');
      img.alt = file.title;
      img.src = fileUrl;
      filePreview.append(img);
    } else if (['text/plain', 'text/csv', 'application/csv'].includes(file.mediaType)) {
      const text = document.createElement('pre');
      text.textContent = new TextDecoder().decode(bytes);
      filePreview.append(text);
    }
    fileDownload.href = fileUrl;
    fileDownload.download = file.name;
    fileDownload.hidden = false;
  }

  filesPanel.addEventListener('toggle', showOfflineFile);
  filesSelect.addEventListener('change', showOfflineFile);

  let unlocked = null;
  let secret = '';
  let generation = 0;
  let timeout;

  function clearPlants() {
    plantPanel.hidden = true;
    plantPanel.open = false;
    plantSearch.value = '';
    plantList.replaceChildren();
    fertilizerFields.querySelectorAll('div').forEach(row => row.remove());
    for (const form of plantForms) {
      form.reset();
      form.hidden = true;
      for (const select of form.querySelectorAll('select')) select.replaceChildren();
    }
  }

  function showPlants() {
    const catalog = unlocked?.plantCatalog;
    plantPanel.hidden = !catalog;
    plantList.replaceChildren();
    if (!catalog) return;
    const search = plantSearch.value.trim().toLocaleLowerCase(locale);
    for (const plant of catalog.plants) {
      const phase = plant.phaseName ?? phaseLabels[plant.phase] ?? plant.phase;
      const details = [plant.strain, phase, ...plant.location].join(' · ');
      if (!`${plant.name} ${details}`.toLocaleLowerCase(locale).includes(search)) continue;
      const item = document.createElement('li');
      const title = document.createElement('strong');
      const info = document.createElement('small');
      title.textContent = plant.name;
      info.textContent = details;
      item.append(title, info);
      plantList.append(item);
    }
    noPlants.hidden = plantList.childElementCount > 0;
    morePlants.hidden = !catalog.truncated;
  }

  function fillChoices(select, choices) {
    for (const choice of choices) {
      const option = document.createElement('option');
      option.value = choice.id;
      option.textContent = choice.name;
      select.append(option);
    }
  }

  function showPlantForms(catalog) {
    if (!catalog) return;
    fillChoices(maintenanceForm.elements.plant, catalog.plants.filter(plant => plant.canMaintain));
    fillChoices(wateringForm.elements.plant, catalog.plants.filter(plant => plant.canWater));
    maintenanceForm.hidden = !maintenanceForm.elements.plant.options.length;
    wateringForm.hidden = !wateringForm.elements.plant.options.length;
    fillChoices(photoForm.elements.plant, catalog.plants.filter(plant => plant.canPhotograph));
    photoForm.hidden = !photoForm.elements.plant.options.length;
    showWateringRecipe();
    const choices = catalog.creation;
    if (!choices?.strains.length || !choices.locations.length) return;
    fillChoices(creationForm.elements.strain, choices.strains);
    fillChoices(creationForm.elements.location, choices.locations);
    creationForm.hidden = false;
  }

  function selectedWateringPlant() {
    return unlocked?.plantCatalog?.plants.find(plant => plant.id === wateringForm.elements.plant.value && plant.canWater);
  }

  function currentRecipe(plant, time) {
    const recipe = plant?.wateringRecipe;
    return recipe?.products.length && (!recipe.validUntil || time < Date.parse(recipe.validUntil)) ? recipe : null;
  }

  function toggleFertilizers() {
    const active = fertilizerToggle.checked;
    fertilizerFields.hidden = !active;
    fertilizerFields.disabled = !active;
    wateringForm.elements.volume.required = active;
    wateringSubmit.textContent = active ? wateringSubmit.dataset.fertilized : wateringSubmit.dataset.plain;
  }

  function showWateringRecipe() {
    const plant = selectedWateringPlant();
    const recipe = currentRecipe(plant, Date.now());
    fertilizerToggle.checked = false;
    fertilizerChoice.hidden = !recipe;
    wateringForm.elements.volume.value = plant?.waterLiters ?? '';
    for (const row of fertilizerFields.querySelectorAll('div')) row.remove();
    for (const product of recipe?.products ?? []) {
      const row = document.createElement('div');
      const choose = document.createElement('input');
      const label = document.createElement('label');
      const balanceLabel = document.createElement('label');
      const balance = document.createElement('select');
      const doseLabel = document.createElement('label');
      const dose = document.createElement('input');
      choose.type = 'checkbox';
      choose.name = 'product:' + product.id;
      choose.checked = true;
      label.append(product.name, choose);
      balance.name = 'balance:' + product.id;
      fillChoices(balance, product.balances);
      balanceLabel.append(fertilizerFields.dataset.source, balance);
      dose.name = 'dose:' + product.id;
      dose.type = 'number';
      dose.min = '0.001';
      dose.max = '1000000';
      dose.step = '0.001';
      dose.inputMode = 'decimal';
      dose.value = product.dose;
      dose.required = true;
      doseLabel.append(fertilizerFields.dataset.dose + ' (' + product.unit + '/L)', dose);
      choose.addEventListener('change', () => { balance.disabled = dose.disabled = !choose.checked; });
      row.append(label, balanceLabel, doseLabel);
      fertilizerFields.append(row);
    }
    toggleFertilizers();
  }

  wateringForm.elements.plant.addEventListener('change', showWateringRecipe);
  fertilizerToggle.addEventListener('change', toggleFertilizers);

  function showQueueError(status, error) {
    const messages = {
      OfflineQueueFull: panel.dataset.queueFull,
      OfflinePhotoInvalid: panel.dataset.photoInvalid,
      OfflineAttachmentInvalid: panel.dataset.attachmentInvalid,
      OfflineAttachmentLimit: panel.dataset.attachmentLimit,
      QuotaExceededError: panel.dataset.deviceStorageFull,
    };
    status.textContent = Object.hasOwn(messages, error?.name) ? messages[error.name] : status.dataset.failed;
  }

  function registerPlantDraft(form, status, details) {
    const button = form.querySelector('button');
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!unlocked?.plantCatalog || button.disabled) return;
      const input = new FormData(form);
      const draft = details(input, unlocked.plantCatalog);
      if (!draft) return;
      const current = unlocked;
      const passphrase = secret;
      button.disabled = true;
      status.textContent = '';
      try {
        const attachment = await stageOfflineAttachment(input.get('attachment'), draft.kind === 'photo');
        await addOfflineDraft({ ...draft, attachment, operation_id: crypto.randomUUID() }, passphrase, current.revision);
        await lock();
        status.textContent = status.dataset.queued;
      } catch (error) {
        showQueueError(status, error);
      } finally {
        button.disabled = false;
      }
    });
  }

  function actionFields(plant, input) {
    return {
      plant_id: plant.id, plant_name: plant.name, version: plant.version,
      comment: input.get('comment').trim(), occurred_at: new Date().toISOString(),
    };
  }

  registerPlantDraft(maintenanceForm, plantStatuses[0], (input, catalog) => {
    const plant = catalog.plants.find(item => item.id === input.get('plant') && item.canMaintain);
    const type = input.get('maintenance_type').trim();
    if (plant && type) return { kind: 'maintenance', ...actionFields(plant, input), type };
  });

  registerPlantDraft(wateringForm, plantStatuses[1], (input, catalog) => {
    const plant = catalog.plants.find(item => item.id === input.get('plant') && item.canWater);
    const volume = input.get('volume') || undefined;
    if (!plant) return;
    const draft = { kind: 'watering', ...actionFields(plant, input), water_liters: volume && Number(volume).toFixed(3) };
    if (!input.has('with_fertilizer')) return draft;
    const recipe = currentRecipe(plant, Date.parse(draft.occurred_at));
    const fertilizers = (recipe?.products ?? []).filter(product => input.has('product:' + product.id)).map(product => ({
      inventory_item_id: product.id, balance_id: input.get('balance:' + product.id), unit: product.unit, name: product.name,
      dose_per_liter: Number(input.get('dose:' + product.id)).toFixed(3),
    }));
    if (!fertilizers.length) {
      plantStatuses[1].textContent = plantStatuses[1].dataset.recipeExpired;
      return;
    }
    return { ...draft, fertilizers };
  });

  registerPlantDraft(creationForm, plantStatuses[2], (input, catalog) => {
    const strain = catalog.creation?.strains.find(item => item.id === input.get('strain'));
    const location = catalog.creation?.locations.find(item => item.id === input.get('location'));
    if (strain && location) return {
      kind: 'creation', name: input.get('plant_name').trim(), strain_id: strain.id, strain_name: strain.name,
      location: { type: location.type, id: location.id }, location_name: location.name,
    };
  });

  registerPlantDraft(photoForm, plantStatuses[3], (input, catalog) => {
    const plant = catalog.plants.find(item => item.id === input.get('plant') && item.canPhotograph);
    if (plant) return { kind: 'photo', plant_id: plant.id, plant_name: plant.name, version: plant.version };
  });

  plantSearch.addEventListener('input', showPlants);

  function clearDraftReview() {
    pending.textContent = '';
    pending.hidden = true;
    draftsOnly.hidden = true;
    draftReview.open = false;
    draftReview.hidden = true;
    draftList.replaceChildren();
  }

  function showPendingDrafts(drafts) {
    if (!drafts.length) return;
    pending.textContent = pending.dataset.label.replace('{count}', new Intl.NumberFormat(locale).format(drafts.length));
    pending.hidden = false;
    draftReview.hidden = false;
    for (const draft of drafts) {
      const item = document.createElement('li');
      const title = document.createElement('strong');
      const detail = document.createElement('p');
      switch (draft.kind) {
        case 'creation':
          title.textContent = (draft.name || draft.strain_name) + ' · ' + draftLabels.planned;
          detail.textContent = [draft.strain_name, draft.location_name].join(' · ');
          break;
        case 'photo':
          title.textContent = draft.plant_name + ' · ' + draftLabels.photo;
          break;
        case 'watering':
          title.textContent = draft.plant_name + ' · ' + (draft.fertilizers?.length ? draftLabels.fertilized : draftLabels.watering);
          detail.textContent = [
            draft.water_liters && draftLabels.volume.replace('{liters}', new Intl.NumberFormat(locale).format(Number(draft.water_liters))),
            draft.fertilizers?.map(product => product.name).join(', '), draft.comment,
          ].filter(Boolean).join(' · ');
          break;
        case 'maintenance':
          title.textContent = draft.plant_name + ' · ' + draft.type;
          detail.textContent = draft.comment;
          break;
        default:
          title.textContent = draft.title;
          detail.textContent = draft.body;
      }
      item.append(title);
      if (draft.occurred_at) {
        const stamp = document.createElement('small');
        stamp.textContent = new Date(draft.occurred_at).toLocaleString(locale);
        item.append(stamp);
      }
      if (detail.textContent) item.append(detail);
      if (draft.attachment?.name) {
        const attachment = document.createElement('small');
        attachment.textContent = draft.attachment.name;
        item.append(attachment);
      }
      if (Object.hasOwn(rejectionLabels, draft.rejection)) {
        const refusal = document.createElement('p');
        refusal.textContent = rejectionLabels[draft.rejection];
        item.append(refusal);
      }
      draftList.append(item);
    }
  }

  async function lock() {
    const current = ++generation;
    window.clearTimeout(timeout);
    result.hidden = true;
    unlocked = null;
    secret = '';
    journalForm.reset();
    siteSelect.replaceChildren();
    field.value = '';
    list.replaceChildren();
    clearDraftReview();
    journalList.replaceChildren();
    clearPlants();
    clearFiles();
    feedback.textContent = '';
    try {
      const state = await readOfflineState();
      if (current !== generation) return;
      panel.hidden = !state.metadata && !state.outbox?.count;
      submit.textContent = state.metadata ? submit.dataset.sites : submit.dataset.drafts;
      submit.disabled = false;
    } catch {
      if (current === generation) panel.hidden = true;
    }
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const current = ++generation;
    window.clearTimeout(timeout);
    submit.disabled = true;
    result.hidden = true;
    unlocked = null;
    secret = '';
    list.replaceChildren();
    clearDraftReview();
    journalList.replaceChildren();
    clearPlants();
    clearFiles();
    feedback.textContent = '';
    queueStatus.textContent = '';
    for (const status of plantStatuses) status.textContent = '';
    const passphrase = field.value;
    field.value = '';
    try {
      const directory = await unlockOfflineSites(passphrase);
      const queue = !directory || directory.pendingCount ? await readOfflineDrafts(passphrase) : null;
      if (current !== generation) return;
      if (directory && queue && (queue.accountId !== directory.accountId || queue.deviceId !== directory.deviceId)) {
        await lock();
        return;
      }
      showPendingDrafts(queue?.drafts ?? []);
      if (!directory) {
        if (!queue?.drafts.length) {
          await lock();
          return;
        }
        empty.hidden = truncated.hidden = journalCopy.hidden = journalForm.hidden = true;
        draftsOnly.hidden = false;
        result.hidden = false;
        timeout = window.setTimeout(lock, 5 * 60_000);
        return;
      }
      const sites = new Map(directory.sites.map(site => [site.id, site.name]));
      const journalSites = directory.journalSiteIds ?? [...sites.keys()];
      journalForm.reset();
      siteSelect.replaceChildren();
      for (const site of directory.sites) {
        const item = document.createElement('li');
        item.textContent = site.name;
        list.append(item);
        if (!journalSites.includes(site.id)) continue;
        const option = document.createElement('option');
        option.value = site.id;
        option.textContent = site.name;
        siteSelect.append(option);
      }
      for (const note of directory.journal ?? []) {
        const item = document.createElement('li');
        const title = document.createElement('strong');
        const details = document.createElement('small');
        const body = document.createElement('p');
        title.textContent = note.title;
        details.textContent = sites.get(note.site_id) + ' · ' + new Date(note.occurred_at).toLocaleString(locale);
        body.textContent = note.body;
        item.append(title, document.createElement('br'), details, body);
        journalList.append(item);
      }
      journalCopy.hidden = journalList.childElementCount === 0;
      unlocked = directory;
      if (directory.files.length) {
        fillChoices(filesSelect, directory.files.map(file => ({ id: file.id, name: file.title })));
        filesPanel.hidden = false;
        filesTimeout = window.setTimeout(lock, Math.max(0, directory.filesExpireAt - Date.now()));
      }
      showPlants();
      showPlantForms(directory.plantCatalog);
      secret = passphrase;
      journalForm.hidden = siteSelect.options.length === 0;
      empty.hidden = directory.sites.length !== 0;
      truncated.hidden = !directory.truncated;
      result.hidden = false;
      timeout = window.setTimeout(lock, Math.max(0, directory.expiresAt - Date.now()));
    } catch {
      if (current === generation) feedback.textContent = panel.dataset.error;
    } finally {
      if (current === generation) submit.disabled = false;
    }
  });
  journalForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!unlocked || queueButton.disabled) return;
    const input = new FormData(journalForm);
    const title = input.get('title').trim();
    const body = input.get('body').trim();
    const site = input.get('site');
    if (!title || !body || !unlocked.sites.some(item => item.id === site)) return;
    queueButton.disabled = true;
    queueStatus.textContent = '';
    try {
      await addOfflineDraft({
        operation_id: crypto.randomUUID(), site_id: site, title, body, occurred_at: new Date().toISOString(),
      }, secret, unlocked.revision);
      await lock();
      queueStatus.textContent = queueStatus.dataset.queued;
    } catch (error) {
      showQueueError(queueStatus, error);
    } finally {
      queueButton.disabled = false;
    }
  });
  panel.querySelector('[data-offline-lock]').addEventListener('click', () => document.dispatchEvent(new Event('offline-sites-lock')));
  document.addEventListener('offline-sites-lock', lock);
  document.addEventListener('visibilitychange', lock);
  watchOfflineSitesChange(lock);
  void lock();
}
