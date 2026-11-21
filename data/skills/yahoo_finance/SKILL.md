---
name: yahoo_finance
description: Trading research for stocks, bonds, commodities, crypto, FX, indexes, and futures. args symbol, action, range, interval, start, end, exchange, type.
version: 1.0.0
---

Use this for trading and financial research: a stock, bond, commodity, crypto coin, currency, index, ETF, fund, or future. A price, a history, a dividend, or a split comes from here. Do not open a Yahoo chart address with fetch_url, and do not install yfinance.

args symbol. action is quote, history, dividends, splits, events, or search. quote is the default. A range or a start date with no action is history. range is a number of days such as 10d or 30d, or 1mo, 3mo, 6mo, 1y, 2y, 5y, 10y, ytd, or max. interval is 1d unless you need 1wk, 1mo, or an intraday bar such as 1h. start and end are YYYY-MM-DD, and they are used together. exchange is a Yahoo suffix such as ST, L, or TO when the same ticker is listed in more than one place. type is stock, etf, fund, index, bond, commodity, crypto, fx, or future when the name could match the wrong instrument.

A plain ticker and the Yahoo symbol are the same call. NIBE-B and NIBE-B.ST both work. NIBE B and NIBE.B are read as NIBE-B. A US class share stays unsuffixed: BRK-B is BRK-B. An index can be ^OMX or OMX. A currency can be EURSEK or EURSEK=X. A crypto pair can be BTC-USD, or type crypto with BTC or bitcoin. A commodity future can be GC=F, CL=F, or SI=F, or type commodity with gold, crude oil, or silver. The ticker GOLD without type is the stock, not the metal. A bond or a yield can be ^TNX or TNX with type bond. A future such as ES=F uses type future. Pass type so an ETF or a miner with a similar name is not used.

quote returns the latest price, the previous close, the day range, the 52-week range, and volume. history returns bars. dividends and splits use the listing currency. events returns both. search only lists matching symbols.

One symbol per call. If several listings match, the result names the symbol it used and the others. Call again with that Yahoo symbol to pick a different one.
