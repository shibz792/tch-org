(() => {
  const state = { data: [], departments: [], filter: '', search: '', collapsed: new Set(), zoom: null };
  const chart = document.getElementById('chart');
  const mobile = document.getElementById('mobileChart');
  chart.querySelector('.loading')?.remove();
  const svg = d3.select(chart).append('svg').attr('width', '100%').attr('height', '100%');
  const defs = svg.append('defs');
  const outlineGradient = defs.append('linearGradient').attr('id', 'brandOutline').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  outlineGradient.append('stop').attr('offset', '0%').attr('stop-color', '#b8dc78');
  outlineGradient.append('stop').attr('offset', '48%').attr('stop-color', '#43b995');
  outlineGradient.append('stop').attr('offset', '100%').attr('stop-color', '#00a99d');
  const titleGradient = defs.append('linearGradient').attr('id', 'titleGradient').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '0%');
  titleGradient.append('stop').attr('offset', '0%').attr('stop-color', '#7fbf5b');
  titleGradient.append('stop').attr('offset', '48%').attr('stop-color', '#169b82');
  titleGradient.append('stop').attr('offset', '100%').attr('stop-color', '#087b76');
  const flowGradient = defs.append('linearGradient').attr('id', 'flowGradient').attr('gradientUnits', 'userSpaceOnUse').attr('x1', '0').attr('y1', '0').attr('x2', '150').attr('y2', '0').attr('spreadMethod', 'repeat');
  flowGradient.append('stop').attr('offset', '0%').attr('stop-color', '#174d55');
  flowGradient.append('stop').attr('offset', '35%').attr('stop-color', '#00a99d');
  flowGradient.append('stop').attr('offset', '68%').attr('stop-color', '#b8dc78');
  flowGradient.append('stop').attr('offset', '100%').attr('stop-color', '#174d55');
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    flowGradient.append('animateTransform')
      .attr('attributeName', 'gradientTransform')
      .attr('type', 'translate')
      .attr('from', '0 0')
      .attr('to', '150 0')
      .attr('dur', '2.4s')
      .attr('repeatCount', 'indefinite');
  }
  const cardHeaderGradient = defs.append('linearGradient').attr('id', 'cardHeaderGradient').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  cardHeaderGradient.append('stop').attr('offset', '0%').attr('stop-color', '#123a40');
  cardHeaderGradient.append('stop').attr('offset', '58%').attr('stop-color', '#087b76');
  cardHeaderGradient.append('stop').attr('offset', '100%').attr('stop-color', '#43b995');
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    outlineGradient.append('animateTransform')
      .attr('attributeName', 'gradientTransform')
      .attr('type', 'rotate')
      .attr('from', '0 .5 .5')
      .attr('to', '360 .5 .5')
      .attr('dur', '2.8s')
      .attr('repeatCount', 'indefinite');
  }
  const stage = svg.append('g');
  const zoom = d3.zoom().scaleExtent([0.25, 2]).on('zoom', event => stage.attr('transform', event.transform));
  state.zoom = zoom;
  svg.call(zoom);

  const text = value => String(value ?? '');
  const photo = value => value || '';
  const textLines = (value, maxChars, maxLines = 2) => {
    const words = text(value).trim().split(/\s+/).filter(Boolean);
    const lines = [];
    for (const word of words) {
      const current = lines[lines.length - 1];
      if (!current || `${current} ${word}`.length > maxChars) lines.push(word);
      else lines[lines.length - 1] = `${current} ${word}`;
    }
    if (lines.length > maxLines) {
      lines.length = maxLines;
      lines[maxLines - 1] = `${lines[maxLines - 1].slice(0, Math.max(1, maxChars - 1))}…`;
    }
    return lines.length ? lines : [''];
  };
  const multiline = (selection, value, x, y, maxChars, maxLines, className, lineHeight = 16) => {
    const label = selection.append('text').attr('class', className).attr('x', x).attr('y', y);
    textLines(value, maxChars, maxLines).forEach((line, index) => {
      label.append('tspan').attr('x', x).attr('dy', index === 0 ? 0 : lineHeight).text(line);
    });
    return label;
  };
  const nameClass = value => {
    const length = text(value).trim().length;
    if (length > 23) return 'node-name node-name-long';
    if (length > 17) return 'node-name node-name-medium';
    return 'node-name';
  };
  const matches = p => {
    const haystack = `${p.name} ${p.title} ${p.department} ${p.location}`.toLowerCase();
    return (!state.search || haystack.includes(state.search)) && (!state.filter || text(p.department_id) === state.filter);
  };
  const prune = nodes => nodes.map(node => ({ ...node, children: prune(node.children || []) }))
    .filter(node => matches(node) || node.children.length);

  function render() {
    stage.selectAll('*').remove();
    const filtered = prune(state.data);
    if (!filtered.length) {
      stage.append('text').attr('x', 40).attr('y', 60).attr('fill', '#687671').text('No people match this view.');
      return;
    }
    const virtualRoot = { name: 'Organization', children: filtered };
    const root = d3.hierarchy(virtualRoot, d => state.collapsed.has(d.id) ? null : d.children);
    d3.tree().nodeSize([415, 340])(root);
    const nodes = root.descendants().slice(1);
    const links = root.links().filter(l => l.target.depth > 1);
    const linkPath = d => {
      const sourceY = d.source.y + 260;
      const targetY = d.target.y;
      const middleY = sourceY + (targetY - sourceY) / 2;
      return `M${d.source.x},${sourceY}V${middleY}H${d.target.x}V${targetY}`;
    };
    stage.selectAll('.link').data(links).enter().append('path').attr('class', 'link')
      .attr('data-target-id', d => d.target.data.id)
      .attr('d', linkPath)
      .style('animation-delay', (_, index) => `${Math.min(index * 28, 420)}ms`);
    const node = stage.selectAll('.node-card').data(nodes).enter().append('g')
      .attr('class', d => `node-card ${d.depth === 1 ? 'top-level' : d.data.direct_reports ? 'manager' : 'individual'}${d.data.is_cherry_global ? ' cherry-global' : ''}`)
      .style('animation-delay', (_, index) => `${Math.min(index * 34, 520)}ms`)
      .attr('tabindex', 0).attr('role', 'button').attr('aria-label', d => `${d.data.name}, ${d.data.title}`)
      .attr('transform', d => `translate(${d.x - 180},${d.y})`)
      .on('click', (_, d) => openProfile(d.data.id))
      .on('mouseenter', (_, d) => focusPath(d))
      .on('mouseleave', clearPath)
      .on('focus', (_, d) => focusPath(d))
      .on('blur', clearPath)
      .on('keydown', (event, d) => { if (event.key === 'Enter' || event.key === ' ') openProfile(d.data.id); });
    node.append('rect').attr('class', 'node-surface').attr('width', 360).attr('height', 260).attr('rx', 26);
    node.append('rect').attr('class', 'gradient-outline').attr('x', -3).attr('y', -3).attr('width', 366).attr('height', 266).attr('rx', 29);
    node.append('path').attr('class', 'node-header').attr('d', 'M26,0H334A26,26 0 0 1 360,26V98H0V26A26,26 0 0 1 26,0Z');
    node.append('circle').attr('class', 'header-orb header-orb-large').attr('cx', 328).attr('cy', 20).attr('r', 64);
    node.append('circle').attr('class', 'header-orb header-orb-small').attr('cx', 272).attr('cy', 86).attr('r', 28);
    node.append('image').attr('class', 'node-watermark').attr('x', 255).attr('y', 148).attr('width', 78).attr('height', 54)
      .attr('href', '/assets/tch-logo.png').attr('preserveAspectRatio', 'xMidYMid meet').attr('aria-hidden', 'true');
    node.append('rect').attr('class', 'node-accent').attr('x', 154).attr('y', 0).attr('width', 86).attr('height', 4).attr('rx', 2)
      .style('fill', d => d.data.is_cherry_global ? '#d2042d' : (d.data.department_color || '#00a99d'));
    node.filter(d => d.data.is_cherry_global).append('g').attr('class', 'cherry-badge').call(badge => {
      badge.append('rect').attr('x', 274).attr('y', 16).attr('width', 68).attr('height', 22).attr('rx', 11);
      badge.append('text').attr('x', 308).attr('y', 31).attr('text-anchor', 'middle').text('CHERRY');
    });
    node.append('circle').attr('class', 'node-avatar-halo').attr('cx', 72).attr('cy', 91).attr('r', 58);
    node.append('circle').attr('class', 'node-avatar-ring').attr('cx', 72).attr('cy', 91).attr('r', 51)
      .attr('fill', d => d.data.department_color || '#00a99d');
    node.filter(d => d.data.photo_path).append('image').attr('class', 'node-avatar').attr('x', 25).attr('y', 44)
      .attr('width', 94).attr('height', 94).attr('href', d => d.data.photo_path)
      .attr('preserveAspectRatio', 'xMidYMid slice');
    node.filter(d => !d.data.photo_path).append('text').attr('class', 'node-initials').attr('x', 72).attr('y', 99)
      .attr('text-anchor', 'middle').text(d => text(d.data.name).split(/\s+/).slice(0, 2).map(part => part[0]).join(''));
    node.append('rect').attr('class', 'department-pill').attr('x', 150).attr('y', 52).attr('width', 192).attr('height', 28).attr('rx', 14);
    node.append('circle').attr('cx', 167).attr('cy', 66).attr('r', 4).attr('fill', d => d.data.department_color || '#b8dc78');
    node.append('text').attr('class', 'node-dept').attr('x', 179).attr('y', 70).text(d => text(d.data.department || 'Organization').slice(0, 24));
    node.each(function(d) {
      const nameLines = textLines(d.data.name, 21, 2);
      const card = d3.select(this);
      const name = card.append('text').attr('class', nameClass(d.data.name)).attr('x', 150).attr('y', 128);
      nameLines.forEach((line, index) => name.append('tspan').attr('x', 150).attr('dy', index === 0 ? 0 : 21).text(line));
      const titleY = 128 + ((nameLines.length - 1) * 21) + 32;
      multiline(card, d.data.title || 'Role not listed', 150, titleY, 25, 2, 'node-title node-role', 19);
    });
    node.append('line').attr('class', 'node-divider').attr('x1', 24).attr('x2', 336).attr('y1', 220).attr('y2', 220);
    node.append('circle').attr('class', 'status-dot').attr('cx', 29).attr('cy', 240).attr('r', 4);
    node.append('text').attr('class', 'node-title node-responsibility').attr('x', 41).attr('y', 244).text(d => d.data.direct_reports ? `${d.data.direct_reports} direct reports` : 'Individual contributor');
    node.filter(d => (d.data.children || []).length).append('circle').attr('class', 'collapse-control').attr('cx', 336).attr('cy', 240).attr('r', 12).attr('fill', '#00a99d')
      .on('click', (event, d) => { event.stopPropagation(); state.collapsed.has(d.data.id) ? state.collapsed.delete(d.data.id) : state.collapsed.add(d.data.id); render(); requestAnimationFrame(fitToChart); });
    node.filter(d => (d.data.children || []).length).append('text').attr('x', 336).attr('y', 244).attr('text-anchor', 'middle').attr('fill', 'white').attr('font-size', 12).attr('pointer-events', 'none').text(d => state.collapsed.has(d.data.id) ? '+' : '−');
    function focusPath(d) {
      const pathIds = new Set(d.ancestors().slice(0, -1).map(item => item.data.id));
      stage.classed('has-focus', true);
      stage.selectAll('.node-card').classed('path-focus', item => pathIds.has(item.data.id)).classed('path-muted', item => !pathIds.has(item.data.id));
      stage.selectAll('.link').classed('path-focus', link => pathIds.has(link.target.data.id)).classed('path-muted', link => !pathIds.has(link.target.data.id));
    }
    function clearPath() {
      stage.classed('has-focus', false);
      stage.selectAll('.node-card,.link').classed('path-focus', false).classed('path-muted', false);
    }
  }

  function renderMobile(nodes = state.data) {
    mobile.replaceChildren();
    const make = items => {
      const fragment = document.createDocumentFragment();
      items.forEach(person => {
        if (!matches(person) && !(person.children || []).some(matches)) return;
        const wrap = document.createElement('div');
        const button = document.createElement('button'); button.className = `mobile-person${person.is_cherry_global ? ' cherry-global' : ''}`;
        const avatar = document.createElement(photo(person.photo_path) ? 'img' : 'span');
        if (photo(person.photo_path)) { avatar.src = photo(person.photo_path); avatar.alt = ''; } else { avatar.className = 'avatar-fallback'; avatar.textContent = text(person.name).charAt(0); }
        const info = document.createElement('span'); const strong = document.createElement('strong'); const small = document.createElement('small');
        strong.textContent = person.name; small.textContent = person.title || 'Role not listed'; info.append(strong, small);
        const count = document.createElement('span'); count.textContent = person.children?.length ? `${person.children.length} ›` : 'View';
        button.append(avatar, info, count); button.addEventListener('click', () => openProfile(person.id)); wrap.append(button);
        if (person.children?.length) { const children = document.createElement('div'); children.className = 'mobile-children open'; children.append(make(person.children)); wrap.append(children); }
        fragment.append(wrap);
      }); return fragment;
    };
    mobile.append(make(nodes));
  }

  async function openProfile(id) {
    const response = await fetch(`/api/?route=person&id=${encodeURIComponent(id)}`);
    const result = await response.json(); if (!result.success) return;
    const p = result.data; const content = document.getElementById('profileContent'); content.replaceChildren();
    const img = document.createElement(p.photo_path ? 'img' : 'div'); img.className = 'profile-photo'; if (p.photo_path) { img.src = p.photo_path; img.alt = ''; } else img.textContent = text(p.name).charAt(0);
    const dept = document.createElement('span'); dept.className = 'eyebrow'; dept.textContent = p.department || 'Organization';
    const name = document.createElement('h2'); name.textContent = p.name; const role = document.createElement('p'); role.className = 'profile-role'; role.textContent = p.title || 'Role not listed';
    const affiliation = document.createElement('div'); affiliation.className = p.is_cherry_global ? 'profile-affiliation cherry-global' : 'profile-affiliation';
    affiliation.textContent = p.is_cherry_global ? 'Cherry Global Resource' : 'Tech Cargo Hub Resource';
    const grid = document.createElement('div'); grid.className = 'profile-grid';
    [['Manager',p.manager_name||'Top level'],['Location',p.location||'Not listed'],['Email',p.email||'Not listed'],['Phone',p.phone||'Not listed']].forEach(([label,value]) => { const box=document.createElement('div');box.className='profile-field';const s=document.createElement('small');s.textContent=label;const v=document.createElement('strong');v.textContent=value;box.append(s,v);grid.append(box); });
    const bio = document.createElement('p'); bio.className = 'profile-bio'; bio.textContent = p.bio || 'Profile details will be added soon.';
    const teamSection = document.createElement('section'); teamSection.className = 'profile-team';
    const teamHeading = document.createElement('h3'); teamHeading.textContent = `Handles ${p.direct_reports?.length || 0} direct reports`; teamSection.append(teamHeading);
    if (p.direct_reports?.length) {
      p.direct_reports.forEach(report => {
        const item = document.createElement('button'); item.className = `profile-team-member${report.is_cherry_global ? ' cherry-global' : ''}`;
        const avatar = document.createElement(report.photo_path ? 'img' : 'span');
        if (report.photo_path) { avatar.src = report.photo_path; avatar.alt = ''; } else { avatar.textContent = text(report.name).charAt(0); }
        const details = document.createElement('span'); const reportName = document.createElement('strong'); const reportRole = document.createElement('small');
        reportName.textContent = report.name; reportRole.textContent = report.title || report.department || 'Role not listed'; details.append(reportName, reportRole);
        const arrow = document.createElement('span'); arrow.textContent = '›'; item.append(avatar, details, arrow);
        item.addEventListener('click', () => openProfile(report.id)); teamSection.append(item);
      });
    } else {
      const empty = document.createElement('p'); empty.className = 'profile-team-empty'; empty.textContent = 'This person has no direct reports.'; teamSection.append(empty);
    }
    content.append(img, dept, name, role, affiliation, grid, bio, teamSection);
    document.getElementById('profileDrawer').classList.add('open'); document.getElementById('profileDrawer').setAttribute('aria-hidden','false'); document.getElementById('drawerBackdrop').classList.add('open');
  }
  const close = () => { document.getElementById('profileDrawer').classList.remove('open'); document.getElementById('profileDrawer').setAttribute('aria-hidden','true'); document.getElementById('drawerBackdrop').classList.remove('open'); };
  document.getElementById('closeDrawer').onclick = close; document.getElementById('drawerBackdrop').onclick = close;
  document.getElementById('searchInput').oninput = e => { state.search = e.target.value.trim().toLowerCase(); render(); renderMobile(); };
  document.getElementById('departmentFilter').onchange = e => { state.filter = e.target.value; render(); renderMobile(); };
  document.getElementById('zoomIn').onclick = () => svg.transition().call(zoom.scaleBy, 1.25); document.getElementById('zoomOut').onclick = () => svg.transition().call(zoom.scaleBy, .8);
  const fitToChart = () => {
    const bounds = stage.node()?.getBBox();
    if (!bounds || !bounds.width || !bounds.height) return;
    const padding = 70;
    const scale = Math.max(.25, Math.min(1, Math.min((chart.clientWidth - padding * 2) / bounds.width, (chart.clientHeight - padding * 2) / bounds.height)));
    const x = chart.clientWidth / 2 - scale * (bounds.x + bounds.width / 2);
    const y = padding - scale * bounds.y;
    svg.transition().duration(350).call(zoom.transform, d3.zoomIdentity.translate(x, y).scale(scale));
  };
  document.getElementById('fitChart').onclick = fitToChart;
  const workspace = document.querySelector('.workspace');
  const presentButton = document.getElementById('fullscreen');
  const exitButton = document.getElementById('exitFullscreen');
  const enterPresentation = async () => {
    if (!document.fullscreenElement) await workspace.requestFullscreen?.();
  };
  const exitPresentation = async () => {
    if (document.fullscreenElement) await document.exitFullscreen?.();
  };
  presentButton.onclick = enterPresentation;
  exitButton.onclick = exitPresentation;
  document.addEventListener('fullscreenchange', () => {
    const presenting = document.fullscreenElement === workspace;
    workspace.classList.toggle('is-presenting', presenting);
    presentButton.textContent = presenting ? 'Presenting' : 'Present';
    presentButton.disabled = presenting;
    setTimeout(fitToChart, 120);
  });
  document.getElementById('printChart').onclick = () => window.print();
  document.getElementById('themeToggle').onclick = () => document.documentElement.classList.toggle('dark');
  fetch('/api/?route=hierarchy').then(r => {
    if (!r.ok) throw new Error(`Chart request failed with status ${r.status}`);
    return r.json();
  }).then(result => {
    if (!result.success || !result.data?.hierarchy) throw new Error('Chart response is invalid');
    state.data = result.data.hierarchy; state.departments = result.data.departments;
    document.getElementById('peopleCount').textContent = count(state.data);
    const select = document.getElementById('departmentFilter'); state.departments.forEach(d => { const o=document.createElement('option');o.value=d.id;o.textContent=d.name;select.append(o); });
    render(); renderMobile(); fitToChart();
  }).catch(error => {
    console.error(error);
    svg.remove();
    const message = document.createElement('div');
    message.className = 'loading';
    message.textContent = 'The organization chart could not be loaded. Refresh the page to try again.';
    chart.append(message);
  });
  const count = nodes => nodes.reduce((total, n) => total + 1 + count(n.children || []), 0);
})();
